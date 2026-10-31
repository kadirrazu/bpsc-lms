<?php

namespace App\Observers;

use App\Models\Designation;
use App\Models\Unit;


use App\Models\User;
use App\Services\Library\Audit;
use Illuminate\Database\Eloquent\Model;

class LibraryObserver
{
    private function fields(Model $m): array
    {
        if ($m instanceof Unit) return ['code','name','sort_order','is_active'];
        return $m instanceof User ? ['name','email','designation_id','unit','role','is_active','preferred_locale','deleted_at'] : ['name','slug','sort_order','is_active'];
    }

    private function snapshot(Model $m, bool $old = false): array
    {
        return array_intersect_key($old ? $m->getRawOriginal() : $m->getAttributes(), array_flip($this->fields($m)));
    }

    public function created(Model $m): void
    {
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
        app(Audit::class)->record(strtolower(class_basename($m)).'.updated', ['changed' => $keys, 'before' => $this->snapshot($m, true), 'after' => $this->snapshot($m)], $m);
    }

    public function deleted(Model $m): void
    {
        app(Audit::class)->record(strtolower(class_basename($m)).'.deleted', ['before' => $this->snapshot($m)], $m);
    }
}
