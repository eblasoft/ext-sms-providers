<?php

namespace Espo\Modules\SmsProviders\Bandwidth;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;

class BandwidthSender implements Sender
{
    private const BASE_URL = 'https://messaging.bandwidth.com/api/v2';

    public function __construct(
        private Config        $config,
        private EntityManager $entityManager,
        private Log           $log
    )
    {
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

        $integration = $this->getIntegrationEntity();

        $bandwidthUsername = $integration->get('bandwidthUsername');
        $bandwidthPassword = $integration->get('bandwidthPassword');
        $bandwidthAccountId = $integration->get('bandwidthAccountId');
        $bandwidthMessagingAppId = $integration->get('bandwidthMessagingAppId');
        $bandwidthSender =
            $sms->getFromNumber() ??
            $integration->get('bandwidthSender') ?? '';

        if (!$bandwidthUsername) {
            throw new Error("No Bandwidth username.");
        }

        if (!$bandwidthPassword) {
            throw new Error("No Bandwidth password.");
        }

        if (!$bandwidthAccountId) {
            throw new Error("No Bandwidth Account ID.");
        }

        if (!$bandwidthMessagingAppId) {
            throw new Error("No Bandwidth Messaging App ID.");
        }

        $url = self::BASE_URL . "/users/$bandwidthAccountId/messages";

        $data = [
            'applicationId' => $bandwidthMessagingAppId,
            'to' => $toNumberList,
            'from' => $bandwidthSender,
            'text' => $sms->getBody(),
            'tag' => 'EspoCRM'
        ];

        $headers = [
            "Authorization: Basic " . base64_encode("$bandwidthUsername:$bandwidthPassword"),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_HEADER, true);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        $body = mb_substr($response, $headerSize);

        if ($code && !($code >= 200 && $code < 300)) {
            $this->log->error("Bandwidth SMS sending error. Code: $code Body: $body.");
            throw new Error("Bandwidth SMS sending error. Code: $code.");
        }

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("Bandwidth SMS sending timeout.");
            }
        }

        $decodedBody = json_decode($body, true);
        $externalId = $decodedBody['id'];
        if (!$externalId) {
            return;
        }
    }

    /**
     * @throws Error
     */
    private function getIntegrationEntity(): Integration
    {
        /* @var $entity Integration */
        $entity = $this->entityManager
            ->getEntityById(Integration::ENTITY_TYPE, 'Bandwidth');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("Bandwidth integration is not enabled");
        }

        return $entity;
    }

    /**
     * @throws Error
     */
    private function sendToNumber(Sms $sms, string $toNumber): void
    {

    }
}
