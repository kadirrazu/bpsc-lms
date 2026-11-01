<?php

namespace App\Observers;

use App\Models\Designation;
use App\Models\Unit;
use App\Models\{Book,NamedCatalogEntry,BookLoan};


use App\Models\User;
use App\Services\Library\Audit;
use App\Services\Library\CatalogRevision;
use Illuminate\Database\Eloquent\Model;

class LibraryObserver
{
    private function fields(Model $m): array
    {
        if ($m instanceof Book) return ['accession_number','title_bn','title_en','publisher_id','call_number','shelf_number','publication_year','number_of_copies','cover_path','deleted_at'];
        if ($m instanceof NamedCatalogEntry) return ['title_bn','title_en'];
        if ($m instanceof BookLoan) return ['book_id','user_id','quantity','issued_at','returned_at'];
        if ($m instanceof Unit) return ['code','name','sort_order','is_active'];
        return $m instanceof User ? ['name','email','mobile_number','designation_id','unit','role','is_active','preferred_locale','deleted_at'] : ['name','slug','sort_order','is_active'];
    }

    private function invalidate(Model $m): void
    {
        if ($m instanceof Book || $m instanceof NamedCatalogEntry) app(CatalogRevision::class)->touch();
        elseif ($m instanceof User) app(CatalogRevision::class)->touch(false);
    }

    private function snapshot(Model $m, bool $old = false): array
    {
        return array_intersect_key($old ? $m->getRawOriginal() : $m->getAttributes(), array_flip($this->fields($m)));
    }

    public function created(Model $m): void
    {
        $this->invalidate($m);
        app(Audit::class)->record(strtolower(class_basename($m)).'.created', ['after' => $this->snapshot($m)], $m);
    }

    public function updated(Model $m): void
    {
        $keys = array_intersect(array_keys($m->getChanges()), $this->fields($m));
        if ($m instanceof User && $m->wasChanged('password')) {
            $keys[] = 'password_changed';
        }
        if (! $keys) {
            return;
        }
        $this->invalidate($m);
        app(Audit::class)->record(strtolower(class_basename($m)).'.updated', ['changed' => $keys, 'before' => $this->snapshot($m, true), 'after' => $this->snapshot($m)], $m);
    }

    public function deleted(Model $m): void
    {
        $this->invalidate($m);
        app(Audit::class)->record(strtolower(class_basename($m)).'.deleted', ['before' => $this->snapshot($m)], $m);
    }
}
