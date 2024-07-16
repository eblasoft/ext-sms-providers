<?php

namespace Espo\Modules\SmsProviders\Hubtel;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;

class HubtelSender implements Sender
{
    private const BASE_URL = 'https://sms.hubtel.com';
    private $config;

    private $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @throws Error
     */
    public function send(Sms $sms): void
    {
        $toNumberList = $sms->getToNumberList();

        if (!count($toNumberList)) {
            throw new Error("No recipient phone number.");
        }

        foreach ($toNumberList as $number) {
            $this->sendToNumber($sms, $number);
        }
    }

    /**
     * @throws Error
     */
    private function sendToNumber(Sms $sms, string $toNumber): void
    {
        $integration = $this->getIntegrationEntity();

        $hubtelClientId = $integration->get('hubtelClientId');
        $apiSecret = $integration->get('hubtelClientSecret');

        $from = $sender =
            $sms->getFromNumber() ??
            $integration->get('hubtelDefaultSender') ?? '';;

        $baseUrl = self::BASE_URL;

        if (!$hubtelClientId) {
            throw new Error("No Hubtel client ID.");
        }

        if (!$apiSecret) {
            throw new Error("No Hubtel client secret.");
        }

        if (!$toNumber) {
            throw new Error("No recipient phone number.");
        }

        if (!$from) {
            throw new Error("No sender phone number.");
        }

        $query = array(
            "clientid" => $hubtelClientId,
            "clientsecret" => $apiSecret,
            "from" => $from,
            "to" => $toNumber,
            "content" => $sms->getBody(),
        );

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $baseUrl . '/v1/messages/send?' . http_build_query($query),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            throw new Error("cURL Error #:" . $error);
        }

        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($statusCode != 201) {
            throw new Error("Hubtel Error: " . $response);
        }

        $response = json_decode($response, true);
        if ($response['status'] != 0) {
            throw new Error("Hubtel Error: " . $response['message']);
        }
    }

    /**
     * @throws Error
     */
    private function getIntegrationEntity(): Integration
    {
        /** @var Integration $entity */
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'Hubtel');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("Hubtel integration is not enabled");
        }

        return $entity;
    }
}

