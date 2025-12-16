<?php

namespace App\Controller;

use App\Repository\DocumentFileRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

final class IndexMonitorController extends BaseController
{
    public const CONTROLLER_NAME = 'IndexMonitorController';

    #[Route('/status/indice', name: 'app_index_monitor', methods: ['GET'])]
    public function monitor(
        DocumentFileRepository $fileRepository,
        Request $request
    ): Response {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $filesData = $fileRepository->createQueryBuilder('f')
            ->leftJoin('f.chunks', 'c')
            ->addSelect('COUNT(c.id) AS chunk_count')
            ->addSelect('SUM(CASE WHEN c.searchable = false THEN 1 ELSE 0 END) AS non_searchable_count')
            ->groupBy('f.id')
            ->orderBy('f.indexedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalFiles = $fileRepository->count([]);
        $totalPages = (int) ceil($totalFiles / $limit);

        return $this->render('index/monitor.html.twig', [
            'controller_name' => self::CONTROLLER_NAME,
            'filesData' => $filesData,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'limit' => $limit,
                'total_items' => $totalFiles,
            ],
        ]);
    }
}
