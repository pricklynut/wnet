<?php

namespace App;

class Searcher
{
    const STATUSES = ['work', 'connecting', 'disconnected'];
    const LIMIT = 500;

    protected $connection;
    protected $customersCache;

    public function __construct($connection)
    {
        $this->connection = $connection;
        mb_internal_encoding("UTF-8");
    }

    public function search($searchString, $filters = [])
    {
        $cacheResults = $this->searchInCache($searchString, $filters);

        if ($cacheResults) {
            return $cacheResults;
        }

        $dbResults = $this->searchInDb($searchString, $filters);

        return $dbResults ? $dbResults : [];
    }

    protected function searchInDb($searchString, $filters = [])
    {
        $filters['statuses'] = array_intersect($filters['statuses'], self::STATUSES); // white list
        $result = [];

        $sql = "
            SELECT t1.id_customer, t1.name_customer, t1.company,
                t2.id_contract, t2.number, t2.date_sign, t2.stuff_number,
                t3.id_service, t3.title_service, t3.status
            FROM obj_customers t1
            LEFT JOIN obj_contracts t2 ON t1.id_customer = t2.id_customer
            LEFT JOIN obj_services t3 ON t2.id_contract = t3.id_contract
        ";

        if (!empty($filters['statuses'])) {
            $filters['statuses'] = array_map(function ($el) {
                return "'" . $el . "'";
            }, $filters['statuses']);
            $sql .= " AND t3.status IN (" . implode(",", $filters['statuses']) . ")";
        }

        $sql .= " WHERE t2.id_contract = ? OR t2.number = ?";

        $sql .= " LIMIT " . self::LIMIT;

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('is', $searchString, $searchString);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $serviceData = !empty($row['id_service']) ? [
                'id_service' => $row['id_service'],
                'title_service' => $row['title_service'],
                'status' => $row['status'],
            ] : [];
            $contractData = !empty($row['id_contract']) ? [
                'id_contract' => $row['id_contract'],
                'number' => $row['number'],
                'date_sign' => $row['date_sign'],
                'stuff_number' => $row['stuff_number'],
            ] : [];
            if ($contractData && $serviceData) {
                $contractData['services'] = [
                    $serviceData['id_service'] => $serviceData
                ];
            }
            $customerData = [
                'id_customer' => $row['id_customer'],
                'name_customer' => $row['name_customer'],
                'company' => $row['company'],
            ];
            if ($contractData) {
                $customerData['contracts'] = [
                    $contractData['id_contract'] => $contractData
                ];
            }

            if (isset($result[$row['id_customer']])
                && isset($result[$row['id_customer']]['contracts'][$row['id_contract']])
                && isset($result[$row['id_customer']]['contracts'][$row['id_contract']]['services'][$row['id_service']])
            ) {
                continue;
            } elseif (isset($result[$row['id_customer']])
                && isset($result[$row['id_customer']]['contracts'][$row['id_contract']])
                && !isset($result[$row['id_customer']]['contracts'][$row['id_contract']]['services'][$row['id_service']])
                && !empty($row['id_service'])
            ) {
                $result[$row['id_customer']]['contracts'][$row['id_contract']]['services'][$row['id_service']] = $serviceData;
                $this->customersCache[$row['id_customer']]['contracts'][$row['id_contract']]['services'][$row['id_service']] = $serviceData;
            } elseif (isset($result[$row['id_customer']])
                && !isset($result[$row['id_customer']]['contracts'][$row['id_contract']])
            ) {
                $result[$row['id_customer']]['contracts'][$row['id_contract']] = $contractData;
                $this->customersCache[$row['id_customer']]['contracts'][$row['id_contract']] = $contractData;
            } else {
                $result[$row['id_customer']] = $customerData;
                $this->customersCache[$row['id_customer']] = $customerData;
            }
        }

        return $result;
    }

    protected function searchInCache($searchString, $filters = [])
    {
        $result = [];

        $customerById = $this->exactSearchInCache('by_id', $searchString, $filters);
        if ($customerById) {
            $result[] = $customerById;
        }

        $customerByName = $this->exactSearchInCache('by_name', $searchString, $filters);
        if ($customerByName) {
            $result[] = $customerByName;
        }

        $nameIndexes = array_keys($this->customersCache['by_name']);

        foreach ($nameIndexes as $nameIndex) {
            if (mb_strpos($nameIndex, $searchString) !== false) {
                $fullTextCustomer = $this->customersCache['by_name'][$nameIndex];
                if ($this->checkStatus($customer, $filters)) {
                    $result[] = $fullTextCustomer;
                }
            }
        }

        return $result;
    }

    protected function exactSearchInCache($cacheName, $searchString, $filters = [])
    {
        if (!isset($this->customersCache[$cacheName][$searchString])) {
            return null;
        }


        $customer = $this->customersCache[$cacheName][$searchString];

        if ($this->checkStatus($customer, $filters)) {
            return $customer;
        }

        return null;
    }

    protected function checkStatus($customer, $filters = [])
    {
        if (empty($filters['statuses'])) {
            return true;
        }

        foreach ($customer['services'] as $service) {
            if (in_array($service['status'], $filters['statuses'])) {
                return true;
            }
        }

        return false;
    }
}
