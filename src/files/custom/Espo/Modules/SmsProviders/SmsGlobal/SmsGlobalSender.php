<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Modules\SmsProviders\SmsGlobal;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use Throwable;

class SmsGlobalSender implements Sender
{
    private const BASE_URL = 'https://api.smsglobal.com';

    const HASH_ALGO = 'sha256';

    private const TIMEOUT = '24:00';

    private $config;

    private $entityManager;

    private $log;

    public function __construct(Config $config, EntityManager $entityManager, Log $log)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->log = $log;
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

        $apiKey = $integration->get('smsGlobalApiKey');
        $apiSecret = $integration->get('smsGlobalApiSecret');
        $baseUrl = self::BASE_URL;

        $sender = $integration->get('smsGlobalSender') ?? '';

        $timeout = $this->config->get('smsGlobalSmsSendTimeout') ?? self::TIMEOUT;

        if (!$apiKey) {
            throw new Error("No SmsGlobal username.");
        }

        if (!$apiSecret) {
            throw new Error("No SmsGlobal password.");
        }

        if (!$toNumber) {
            throw new Error("No recipient phone number.");
        }

        $jsonPayload = json_encode([
            "destination" => $toNumber,
            "message" => $sms->getBody(),
            "origin" => $sender
        ], JSON_FORCE_OBJECT);

        if (!$jsonPayload) {
            throw new Error('Invalid payload ' . json_last_error_msg());
        }

        $headers = [
            'Authorization: ' . $this->getAuthorizationHeader($apiKey, $apiSecret),
            'Content-Type: application/json',
        ];

        $url = $baseUrl . '/v2/sms';

        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        $body = mb_substr($response, $headerSize);

        if ($code && !($code >= 200 && $code < 300)) {
            $this->processError($code, $body);
        }

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("SmsGlobal SMS sending timeout.");
            }
        }
    }

    /**
     * @throws Error
     */
    private function getIntegrationEntity(): Integration
    {
        /** @var Integration $entity */
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'SmsGlobal');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("SmsGlobal integration is not enabled");
        }

        return $entity;
    }

    private function getAuthorizationHeader(string $apiKey, string $apiSecret): string
    {
        $timestamp = time();
        $nonce = md5(microtime() . mt_rand());

        $hash = $this->hashRequest($timestamp, $nonce, $apiSecret);
        $header = 'MAC id="%s", ts="%s", nonce="%s", mac="%s"';
        $header = sprintf($header, $apiKey, $timestamp, $nonce, $hash);

        return $header;
    }

    private function hashRequest(int $timestamp, string $nonce, string $apiSecret): string
    {
        $string = array($timestamp, $nonce, 'POST', '/v2/sms', 'api.smsglobal.com', 443, '');
        $string = sprintf("%s\n", implode("\n", $string));
        $hash = hash_hmac(self::HASH_ALGO, $string, $apiSecret, true);
        $hash = base64_encode($hash);

        return $hash;
    }

    /**
     * @throws Error
     */
    private function processError(int $code, string $body): void
    {
        try {
            $data = Json::decode($body);

            $message = $data->message ?? null;
        } catch (Throwable $e) {
            $message = null;
        }

        if ($message) {
            $this->log->error("SmsGlobal SMS sending error. Message: " . $message);
        }

        throw new Error("SmsGlobal SMS sending error. Code: {$code}.");
    }
}
