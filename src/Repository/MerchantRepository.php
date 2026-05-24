<?php

namespace App\Repository;

use App\Entity\Merchant;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Merchant>
 */
class MerchantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Merchant::class);
    }

    /**
     * Returns geocoded merchants within $radiusKm of the given coordinates,
     * ordered by distance ASC. If no coordinates are provided, returns all
     * geocoded merchants ordered by company name.
     *
     * Uses native DBAL SQL for the Haversine formula because ACOS/COS/SIN/RADIANS
     * are not registered DQL functions in Doctrine ORM.
     *
     * @return array<int, array{merchant: Merchant, distance_km: float|null}>
     */
    /**
     * Returns geocoded merchants within the given map bounding box,
     * ordered by distance from user coords (if provided) or by name.
     *
     * Uses a simple BETWEEN filter on lat/lng columns (indexable) for viewport filtering,
     * combined with Haversine only for distance ordering — no HAVING needed.
     *
     * @return array<int, array{merchant: Merchant, distance_km: float|null}>
     */
    public function findForMap(
        ?float $lat,
        ?float $lng,
        float $swLat,
        float $swLng,
        float $neLat,
        float $neLng,
        ?string $establishmentType = null,
    ): array {
        $establishmentType = $establishmentType !== null ? trim($establishmentType) : null;
        $establishmentType = $establishmentType !== '' ? $establishmentType : null;

        $establishmentJoin = '';
        $establishmentWhere = '';
        $params = [
            'sw_lat' => $swLat,
            'sw_lng' => $swLng,
            'ne_lat' => $neLat,
            'ne_lng' => $neLng,
        ];

        if ($lat !== null && $lng !== null) {
            // With user coords: compute distance for ordering and display
            $sql = '
                SELECT BIN_TO_UUID(id) AS id,
                    (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(latitude))
                        * COS(RADIANS(longitude) - RADIANS(:lng))
                        + SIN(RADIANS(:lat)) * SIN(RADIANS(latitude))
                    )) AS distance_km
                FROM merchant
                                ' . $establishmentJoin . '
                WHERE latitude IS NOT NULL
                  AND longitude IS NOT NULL
                  AND latitude  BETWEEN :sw_lat AND :ne_lat
                  AND longitude BETWEEN :sw_lng AND :ne_lng
                                    ' . $establishmentWhere . '
                ORDER BY distance_km ASC
            ';
                        $params['lat'] = $lat;
                        $params['lng'] = $lng;
        } else {
            // No user coords: bounding box filter only, alphabetical order
            $sql = '
                SELECT BIN_TO_UUID(id) AS id, NULL AS distance_km
                FROM merchant
                                ' . $establishmentJoin . '
                WHERE latitude IS NOT NULL
                  AND longitude IS NOT NULL
                  AND latitude  BETWEEN :sw_lat AND :ne_lat
                  AND longitude BETWEEN :sw_lng AND :ne_lng
                                    ' . $establishmentWhere . '
                ORDER BY company_name ASC
            ';
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $params)
            ->fetchAllAssociative();

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $distanceById = array_column($rows, 'distance_km', 'id');

        // Convert RFC4122 strings → Uuid objects so Doctrine's type system
        // correctly converts them to BINARY(16) when building the IN clause.
        $uuids = array_map(fn(string $id) => Uuid::fromString($id), $ids);

        // findBy() with an array generates IN and applies UuidType conversion per element
        $merchants = $this->findBy(['id' => $uuids]);

        $merchantById = [];
        foreach ($merchants as $m) {
            $merchantById[$m->getId()->toRfc4122()] = $m;
        }

        $mapped = [];
        foreach ($ids as $id) {
            if (isset($merchantById[$id])) {
                $d = $distanceById[$id];
                $mapped[] = [
                    'merchant'    => $merchantById[$id],
                    'distance_km' => $d !== null ? round((float) $d, 2) : null,
                ];
            }
        }

        return $mapped;
    }

    /**
     * Returns merchants that have an address but no geocoordinates yet.
     *
     * @return Merchant[]
     */
    public function findNotGeocodedWithAddress(int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.latitude IS NULL')
            ->andWhere('m.address IS NOT NULL AND m.address != \'\'')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countNotGeocodedWithAddress(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.latitude IS NULL')
            ->andWhere('m.address IS NOT NULL AND m.address != \'\'')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Merchant[]
     */
    public function findUnclaimedByEmail(string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.user IS NULL')
            ->andWhere('LOWER(m.email) = :email')
            ->setParameter('email', $normalized)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search geocoded merchants by company name or full address text.
     *
     * @return array<int, array{
     *   id: string,
     *   company_name: string,
     *   address: string|null,
     *   postal_code: string|null,
     *   city: string|null,
     *   latitude: float,
     *   longitude: float,
     *   distance_km: float|null
     * }>
     */
    public function searchForMap(string $query, int $limit, ?float $lat = null, ?float $lng = null): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        $like = '%' . $normalized . '%';
        $prefixLike = $normalized . '%';

        if ($lat !== null && $lng !== null) {
            $sql = '
                SELECT
                    BIN_TO_UUID(id) AS id,
                    company_name,
                    address,
                    postal_code,
                    city,
                    latitude,
                    longitude,
                    (6371 * ACOS(
                        COS(RADIANS(:lat)) * COS(RADIANS(latitude))
                        * COS(RADIANS(longitude) - RADIANS(:lng))
                        + SIN(RADIANS(:lat)) * SIN(RADIANS(latitude))
                    )) AS distance_km
                FROM merchant
                WHERE latitude IS NOT NULL
                  AND longitude IS NOT NULL
                  AND (
                    LOWER(company_name) LIKE :like
                    OR LOWER(CONCAT_WS(\' \', address, postal_code, city)) LIKE :like
                  )
                ORDER BY
                    CASE
                      WHEN LOWER(company_name) LIKE :prefix_like THEN 0
                      WHEN LOWER(CONCAT_WS(\' \', address, postal_code, city)) LIKE :prefix_like THEN 1
                      ELSE 2
                    END ASC,
                    distance_km ASC,
                    company_name ASC
                LIMIT :limit
            ';

            $params = [
                'lat' => $lat,
                'lng' => $lng,
                'like' => $like,
                'prefix_like' => $prefixLike,
                'limit' => $limit,
            ];
        } else {
            $sql = '
                SELECT
                    BIN_TO_UUID(id) AS id,
                    company_name,
                    address,
                    postal_code,
                    city,
                    latitude,
                    longitude,
                    NULL AS distance_km
                FROM merchant
                WHERE latitude IS NOT NULL
                  AND longitude IS NOT NULL
                  AND (
                    LOWER(company_name) LIKE :like
                    OR LOWER(CONCAT_WS(\' \', address, postal_code, city)) LIKE :like
                  )
                ORDER BY
                    CASE
                      WHEN LOWER(company_name) LIKE :prefix_like THEN 0
                      WHEN LOWER(CONCAT_WS(\' \', address, postal_code, city)) LIKE :prefix_like THEN 1
                      ELSE 2
                    END ASC,
                    company_name ASC
                LIMIT :limit
            ';

            $params = [
                'like' => $like,
                'prefix_like' => $prefixLike,
                'limit' => $limit,
            ];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                $sql,
                $params,
                ['limit' => ParameterType::INTEGER],
            )
            ->fetchAllAssociative();

        return array_map(static fn(array $row) => [
            'id' => (string) $row['id'],
            'company_name' => (string) $row['company_name'],
            'address' => $row['address'] !== null ? (string) $row['address'] : null,
            'postal_code' => $row['postal_code'] !== null ? (string) $row['postal_code'] : null,
            'city' => $row['city'] !== null ? (string) $row['city'] : null,
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'distance_km' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 2) : null,
        ], $rows);
    }

}
