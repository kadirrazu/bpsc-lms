<?php

namespace App\Services\Library;

use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CopyAccessions
{
    public static function numbers(array $data): array
    {
        $additional = preg_split('/[;\r\n]+/', trim($data['additional_accessions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map('trim', [$data['accession_number'] ?? '', ...$additional]), fn ($number) => $number !== ''));
    }

    public function validate(array $data, ?int $bookId = null): array
    {
        $numbers = self::numbers($data);
        $expected = ($data['format'] ?? 'hard') === 'soft' ? 1 : (int) ($data['number_of_copies'] ?? 1);
        $field = $expected === 1 ? 'accession_number' : 'additional_accessions';
        if (count($numbers) !== $expected) {
            throw ValidationException::withMessages([$field => "Enter exactly $expected accession number(s), one per copy."]);
        }
        $keys = array_map('mb_strtolower', $numbers);
        if (count(array_unique($keys)) !== count($numbers)) {
            throw ValidationException::withMessages([$field => 'Each accession number must be unique.']);
        }
        foreach ($numbers as $number) {
            if (! mb_check_encoding($number, 'UTF-8') || mb_strlen($number) > 100 || preg_match('/\p{Cc}/u', $number)) {
                throw ValidationException::withMessages([$field => 'Use at most 100 characters per accession number and omit control characters.']);
            }
        }
        // Check the catalog column too, including records created by older integrations.
        foreach (array_chunk($numbers, 200) as $chunk) {
            $conflict = BookCopy::whereNull('accession_released_at')->whereIn('accession_number', $chunk)
                ->when($bookId, fn ($query) => $query->where('book_id', '<>', $bookId))->value('accession_number')
                ?? Book::whereIn('accession_number', $chunk)
                    ->when($bookId, fn ($query) => $query->whereKeyNot($bookId))->value('accession_number');
            if ($conflict !== null) {
                throw ValidationException::withMessages([$field => "Accession $conflict is already in use or reserved."]);
            }
        }

        return $numbers;
    }

    /** The caller holds the book lock and wraps this in the book save transaction. */
    public function sync(Book $book, array $numbers): void
    {
        $current = $book->copies()->get()->keyBy(fn (BookCopy $copy) => mb_strtolower($copy->accession_number));
        $activeNumbers = $book->isSoftCopy() ? [] : array_flip($numbers);
        $removed = $current->filter(fn (BookCopy $copy) => $copy->is_active && ! isset($activeNumbers[$copy->accession_number]));
        $previous = $current->where('is_active', true)->pluck('accession_number')->sort()->values()->all();
        if ($removed->isNotEmpty() && $book->loans()->whereNull('returned_at')
            ->whereIn('book_copy_id', $removed->modelKeys())->exists()) {
            throw ValidationException::withMessages(['additional_accessions' => 'Return the affected copies before removing or changing their accession numbers.']);
        }
        try {
            foreach ($numbers as $number) {
                $copy = $current->get(mb_strtolower($number));
                if ($copy) {
                    $copy->update(['accession_number' => $number, 'is_active' => ! $book->isSoftCopy()]);
                } else {
                    $book->copies()->create(['accession_number' => $number, 'is_active' => ! $book->isSoftCopy()]);
                }
            }
            foreach ($removed as $copy) {
                if (! isset($activeNumbers[$copy->accession_number])) {
                    $copy->update(['is_active' => false]);
                }
            }
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['additional_accessions' => 'An accession was registered elsewhere. Check the numbers and try again.']);
        }
        $next = array_keys($activeNumbers);
        $next = array_map('strval', $next);
        sort($next, SORT_REGULAR);
        if ($previous !== $next) {
            app(CatalogRevision::class)->touch();
            app(Audit::class)->record('book.copy_accessions_updated', ['accessions' => $numbers], $book);
        }
    }
}
