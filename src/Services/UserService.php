<?php

namespace App\Services;

use App\Entity\Family;
use App\Entity\Fruits;
use App\Entity\Genus;
use App\Entity\Nutritions;
use App\Entity\Orders;
use App\Entity\Status;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Entity\Log;

class UserService
{
  private $em;
  private $client;

  public function __construct(EntityManagerInterface $em, HttpClientInterface $client)
  {
    $this->em = $em;
    $this->client = $client;
  }

  /**
   * @throws TransportExceptionInterface
   * @throws ServerExceptionInterface
   * @throws RedirectionExceptionInterface
   * @throws DecodingExceptionInterface
   * @throws ClientExceptionInterface
   * @throws Exception
   */
  public function fetchAndSaveFruits()
  {
    $response = $this->client->request('GET', 'https://fruityvice.com/api/fruit/all');
    $statuses = $this->em->getRepository(Status::class)->findAll();
    $status = $statuses[0]->getId();
    $checkApiStatus = $this->checkApiStatus($response);
    if (!$checkApiStatus) {
      $status = $statuses[1]->getId();
    }

    $allFruitsData = $response->toArray();

    // Initialization counters
    $counters = [
      'noFruitsInsert' => 0,
      'noFruitsUpdated' => 0,
      'noFamilyInsert' => 0,
      'noFamilyUpdated' => 0,
      'noGenusInsert' => 0,
      'noGenusUpdated' => 0,
      'noOrderInsert' => 0,
      'noOrderUpdated' => 0,
      'noNutritionInsert' => 0,
      'noNutritionUpdated' => 0,
    ];
    $errors = [];

    foreach ($allFruitsData as $singleFruitData) {
      try {
        $genus = $this->addOrUpdateGenusFruits($singleFruitData);
        if (!empty($genus['error'])) {
          $errors[] = $genus['error'];
        }

        $family = $this->addOrUpdateFamilyFruits($singleFruitData);
        if (!empty($family['error'])) {
          $errors[] = $family['error'];
        }

        $order = $this->addOrUpdateOrderFruits($singleFruitData);
        if (!empty($order['error'])) {
          $errors[] = $order['error'];
        }

        $nutrition = $this->addOrUpdateNutritions($singleFruitData);
        if (!empty($nutrition['error'])) {
          $errors[] = $nutrition['error'];
        }

        $fruits = $this->addOrUpdateFruits($singleFruitData);
        if (!empty($fruits['error'])) {
          $errors[] = $fruits['error'];
        }

        $keys = [
          'noGenusInsert', 'noGenusUpdated',
          'noFamilyInsert', 'noFamilyUpdated',
          'noOrderInsert', 'noOrderUpdated',
          'noNutritionInsert', 'noNutritionUpdated',
          'noFruitsInsert', 'noFruitsUpdated'
        ];

        foreach ($keys as $key) {
          $counters[$key] += $genus[$key] ?? 0;
          $counters[$key] += $family[$key] ?? 0;
          $counters[$key] += $order[$key] ?? 0;
          $counters[$key] += $nutrition[$key] ?? 0;
          $counters[$key] += $fruits[$key] ?? 0;
        }
      } catch (\Exception $e) {
        $status = $statuses[2]->getId();;
        dump($e->getMessage());
      }
    }

    $this->em->flush();



    $this->saveFruitLogs($counters, $status, implode('; ', $errors));
  }

  private function checkApiStatus($response): bool
  {
    try {

      if ($response->getStatusCode() === 200) {
        return true;
      }
    } catch (TransportExceptionInterface $e) {
      dump($e->getMessage());
    } catch (Exception $e) {
      dump($e->getMessage());
    }

    return false;
  }


  /**
   * @throws Exception
   */
  public function saveFruitLogs(array $counters, int $status, string $errorMessages): bool
  {
    try {
      $log = new Log();
      $log->setDate(new \DateTime());
      $log->setNoFruitsInsert($counters['noFruitsInsert']);
      $log->setNoFruitsUpdated($counters['noFruitsUpdated']);
      $log->setNoFamilyInsert($counters['noFamilyInsert']);
      $log->setNoFamilyUpdated($counters['noFamilyUpdated']);
      $log->setNoGenusInsert($counters['noGenusInsert']);
      $log->setNoGenusUpdated($counters['noGenusUpdated']);
      $log->setNoOrderInsert($counters['noOrderInsert']);
      $log->setNoOrderUpdated($counters['noOrderUpdated']);
      $log->setNoNutritionInsert($counters['noNutritionInsert']);
      $log->setNoNutritionUpdate($counters['noNutritionUpdated']);
      $log->setErrorMessage($errorMessages);
      $log->setNotificationMailSent(0);
      $log->setStatus($status);

      $this->em->persist($log);
      $this->em->flush();

      return true;
    } catch (Exception $e) {

      dump($e->getMessage());

      return false;
    }
  }


  public function addOrUpdateFruits(array $fruitData): array
  {
    $noFruitsInsert = 0;
    $noFruitsUpdated = 0;

    $fruit = $this->em->getRepository(Fruits::class)->findOneBy(['fruitId' => $fruitData['id']]);
    $genus = $this->em->getRepository(Genus::class)->findOneBy(['name' => $fruitData['genus']]);
    $family = $this->em->getRepository(Family::class)->findOneBy(['name' => $fruitData['family']]);
    $order = $this->em->getRepository(Orders::class)->findOneBy(['name' => $fruitData['order']]);
    $nutritions = $this->em->getRepository(Nutritions::class)->findOneBy(['fruitId' => $fruitData['id']]);


    if (!$fruit) {
      // Insert New Fruits
      $fruit = new Fruits();
      $fruit->setFruitId($fruitData['id']);
      $fruit->setName($fruitData['name']);
      $fruit->setGenus($genus);
      $fruit->setFamily($family);
      $fruit->setOrder($order);
      if ($nutritions) {
        $fruit->setNutrition($nutritions);
      }
      $this->em->persist($fruit);
      $noFruitsInsert++;
    } else {
      // Codul pentru actualizarea fructului existent
      $fruit->setName($fruitData['name']);
      $fruit->setGenus($genus);
      $fruit->setFamily($family);
      $fruit->setOrder($order);
      if ($nutritions) {
        $fruit->setNutrition($nutritions);
      }
      $noFruitsUpdated++;
    }

    // Save Fruits
    $this->em->persist($fruit);

    try {
      $this->em->flush();
    } catch (\Exception $e) {
      return [
        'noFruitsInsert'  => 0,
        'noFruitsUpdated' => 0,
        'error'           => $e->getMessage(),
      ];
    }

    return [
      'noFruitsInsert'  => $noFruitsInsert,
      'noFruitsUpdated' => $noFruitsUpdated,
      'error'           => null,
    ];
  }


  /**
   * @throws Exception
   */
  /**
   * @throws Exception
   */
  private function addOrUpdateNutritions(array $fruitData): array
  {
    $noNutritionInsert = 0;
    $noNutritionUpdated = 0;

    try {
      $nutrition = $this->em->getRepository(Nutritions::class)->findOneBy(['fruitId' => $fruitData['id']]);

      if ($nutrition && isset($fruitData['nutritions'])) {
        $nutrition->setCarbohydrates($fruitData['nutritions']['carbohydrates']);
        $nutrition->setProtein($fruitData['nutritions']['protein']);
        $nutrition->setFat($fruitData['nutritions']['fat']);
        $nutrition->setCalories($fruitData['nutritions']['calories']);
        $nutrition->setSugar($fruitData['nutritions']['sugar']);
        $noNutritionUpdated++;
      } else {
        if (!empty($fruitData['nutritions'])) {
          $nutrition = new Nutritions();
          $nutrition->setCarbohydrates($fruitData['nutritions']['carbohydrates']);
          $nutrition->setProtein($fruitData['nutritions']['protein']);
          $nutrition->setFat($fruitData['nutritions']['fat']);
          $nutrition->setCalories($fruitData['nutritions']['calories']);
          $nutrition->setSugar($fruitData['nutritions']['sugar']);
          $nutrition->setFruitId($fruitData['id']);
          $this->em->persist($nutrition);
          $noNutritionInsert++;
        }
      }

      $this->em->flush();
    } catch (\Exception $e) {
      return [
        'noNutritionInsert'  => 0,
        'noNutritionUpdated' => 0,
        'error'              => $e->getMessage(),
      ];
    }

    return [
      'noNutritionInsert'  => $noNutritionInsert,
      'noNutritionUpdated' => $noNutritionUpdated,
      'error'              => null,
    ];
  }

  public function addOrUpdateFamilyFruits(array $fruitData): array
  {
    $noFamilyInsert = 0;
    $noFamilyUpdated = 0;

    $family = $this->em->getRepository(Family::class)->findOneBy(['name' => $fruitData['family']]);

    if (!$family) {
      $family = new Family();
      $family->setName($fruitData['family']);
      $noFamilyInsert++;
      $this->em->persist($family);
    } else {
      $noFamilyUpdated++;
    }

    try {
      $this->em->flush();
    } catch (\Exception $e) {
      return [
        'noFamilyInsert'  => 0,
        'noFamilyUpdated' => 0,
        'error'           => $e->getMessage(),
      ];
    }

    return [
      'noFamilyInsert'  => $noFamilyInsert,
      'noFamilyUpdated' => $noFamilyUpdated,
      'error'           => null,
    ];
  }

  public function addOrUpdateGenusFruits(array $fruitData): array
  {
    $noGenusInsert = 0;
    $noGenusUpdated = 0;

    $genus = $this->em->getRepository(Genus::class)->findOneBy(['name' => $fruitData['genus']]);

    if (!$genus) {
      $genus = new Genus();
      $genus->setName($fruitData['genus']);
      $noGenusInsert++;
      $this->em->persist($genus);
    } else {
      $noGenusUpdated++;
    }


    try {
      $this->em->flush();
    } catch (\Exception $e) {
      return [
        'noGenusInsert'  => 0,
        'noGenusUpdated' => 0,
        'error'          => $e->getMessage(),
      ];
    }

    return [
      'noGenusInsert'  => $noGenusInsert,
      'noGenusUpdated' => $noGenusUpdated,
      'error'          => null,
    ];
  }

  public function addOrUpdateOrderFruits(array $fruitData): array
  {
    $noOrderInsert = 0;
    $noOrderUpdated = 0;

    $order = $this->em->getRepository(Orders::class)->findOneBy(['name' => $fruitData['order']]);
    if (!$order) {
      $order = new Orders();
      $order->setName($fruitData['order']);
      $this->em->persist($order);
      $noOrderInsert++;
    } else {
      $noOrderUpdated++;
    }

    try {
      $this->em->flush();
    } catch (\Exception $e) {
      return [
        'noOrderInsert'  => 0,
        'noOrderUpdated' => 0,
        'error'          => $e->getMessage(),
      ];
    }

    return [
      'noOrderInsert'  => $noOrderInsert,
      'noOrderUpdated' => $noOrderUpdated,
      'error'          => null,
    ];
  }

}
