<?php

namespace App\Repositories;

use App\Interfaces\KeywordRepositoryInterface;
use App\Models\Keyword;

class KeywordRepository implements KeywordRepositoryInterface
{
    public function all()
    {
        return Keyword::all();
    }

    public function find($id)
    {
        return Keyword::findOrFail($id);
    }

    public function create(array $data)
    {
        return Keyword::create($data);
    }

    public function update($id, array $data)
    {
        $keyword = Keyword::findOrFail($id);
        $keyword->update($data);
        return $keyword;
    }

    public function delete($id)
    {
        $keyword = Keyword::findOrFail($id);
        $keyword->delete();
        return true;
    }
}
