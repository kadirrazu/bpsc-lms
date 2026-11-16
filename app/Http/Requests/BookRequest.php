<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Book;
class BookRequest extends FormRequest {
 public function authorize(): bool {return $this->user()?->can('manage-library') ?? false;}
 public function rules(): array {
  $book = $this->route('book');
  $soft = ($book?->format ?? $this->input('format', 'hard')) === 'soft';
  return [
  'format'=>['sometimes',Rule::in($book ? [$book->format] : ['hard','soft'])],
  'additional_accessions'=>'nullable|string|max:50500',
  'printed_price'=>'nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
  'printed_currency'=>['nullable',Rule::in(array_keys(Book::PRINTED_CURRENCIES))],
  'pdf'=>[$soft && !$book?->pdf_path ? 'required' : 'nullable','bail','file','extensions:pdf,epub','max:51200',Rule::prohibitedIf(!$soft),new \App\Rules\DigitalBookFile],
  'accession_number'=>['required','string','max:100',Rule::unique('books','accession_number')->ignore($this->route('book')?->id)],
  'title_bn'=>'required|string|max:255','title_en'=>'nullable|string|max:255',
  'author_ids'=>'required|array|min:1|max:50','author_ids.*'=>'required|integer|distinct|exists:authors,id','publisher_id'=>'required|integer|exists:publishers,id','category_ids'=>'required|array|min:1|max:50','category_ids.*'=>'required|integer|distinct|exists:categories,id',
  'call_number'=>$soft ? 'nullable|string|max:100' : 'required|string|max:100','shelf_number'=>'nullable|string|max:100','publication_year'=>'nullable|integer|min:1|max:9999',
  'price'=>'nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
  'collection_method'=>'nullable|in:Purchase,Gift,Others',
  'number_of_copies'=>$soft ? 'exclude' : 'required|integer|min:1|max:500',
  'cover'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:5120','dimensions:max_width=6000,max_height=6000'],
  'remove_cover'=>'sometimes|boolean',
 ];}
 public function attributes(): array {return ['title_bn'=>'Bengali title','title_en'=>'English title','author_ids'=>'authors','author_ids.*'=>'author','publisher_id'=>'publisher','category_ids'=>'categories','category_ids.*'=>'category','cover'=>'cover image','pdf'=>'PDF or EPUB file'];}
}
