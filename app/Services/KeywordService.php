<?php

namespace App\Services;

use App\Interfaces\Repositories\KeywordRepositoryInterface;

class KeywordService
{
    protected KeywordRepositoryInterface $keywordRepository;
    protected ApiResponseModifier $response;

    public function __construct(KeywordRepositoryInterface $keywordRepository, ApiResponseModifier $response)
    {
        $this->keywordRepository = $keywordRepository;
        $this->response = $response;
    }

    public function getAllKeywords()
    {
        $data = $this->keywordRepository->all();
        return $this->response->setData($data)->response();
    }

    public function createKeyword(array $data)
    {
        $keyword = $this->keywordRepository->create($data);
        return $this->response->setMessage('Keyword created successfully')->setData($keyword)->response();
    }

    public function updateKeyword($id, array $data)
    {
        $keyword = $this->keywordRepository->update($id, $data);
        return $this->response->setMessage('Keyword updated successfully')->setData($keyword)->response();
    }

    public function deleteKeyword($id)
    {
        $this->keywordRepository->delete($id);
        return $this->response->setMessage('Keyword deleted successfully')->setResponseCode(204)->response();
    }
}
