<?php
/************************************************************************
 * This file is part of SMS Providers extension for EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
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

namespace Espo\Modules\SmsProviders\MNotify;

use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Json;
use Espo\Core\Exceptions\Error;
use Espo\ORM\EntityManager;
use Espo\Entities\Integration;
use Throwable;

class MNotifySender implements Sender
{
    private const BASE_URL = 'https://api.mnotify.com/api/sms/quick';

    private const TIMEOUT = 15;

    private $config;

    private $entityManager;

    private $log;

    public function __construct(Config $config, EntityManager $entityManager, Log $log)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function send(Sms $sms): void
    {
        $toNumberList = $sms->getToNumberList();

        if (!count($toNumberList)) {
            throw new Error("No recipient phone number.");
        }

        $this->sendBulk($sms, $toNumberList);
    }

    private function sendBulk(Sms $sms, array $toNumberList): void
    {
        $integration = $this->getIntegrationEntity();

        $apiKey = $integration->get('mNotifyApiKey');
        $baseUrl = rtrim(
            $integration->get('mNotifyApiBaseUrl') ??
            $this->config->get('mNotifyApiBaseUrl') ??
            self::BASE_URL
        );
        $timeout = $this->config->get('mNotifySmsSendTimeout') ?? self::TIMEOUT;

        $fromNumber = $sms->getFromNumber();

        if (!$apiKey) {
            throw new Error("No MNotify API Key.");
        }

        if (!$fromNumber) {
            throw new Error("No sender ID.");
        }

        $url = $baseUrl . '?key=' . urlencode($apiKey);

        $data = [
            'recipient' => $toNumberList,
            'sender' => $fromNumber,
            'message' => $sms->getBody(),
            'is_schedule' => false,
            'schedule_date' => '',
        ];

        $headers = [
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_HEADER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_POST, true);
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, Json::encode($data));

        $response = curl_exec($ch);

        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        curl_close($ch);

        $body = mb_substr($response, $headerSize);

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("MNotify SMS sending timeout.");
            }

            throw new Error("MNotify SMS sending error. cURL error: {$error}");
        }

        if ($code && !($code >= 200 && $code < 300)) {
            $this->processError($code, $body);
        }

        $this->processResponse($body);
    }

    private function processResponse(string $body): void
    {
        try {
            $data = Json::decode($body);

            if (isset($data->status) && $data->status === 'success') {
                if (isset($data->summary->_id)) {
                    $this->log->info("MNotify SMS sent successfully. Campaign ID: " . $data->summary->_id);
                }
                return;
            }

            $message = $data->message ?? 'Unknown error';
            $this->log->error("MNotify SMS sending failed. Message: " . $message);
            throw new Error("MNotify SMS sending failed: {$message}");
        }
        catch (Throwable $e) {
            if ($e instanceof Error) {
                throw $e;
            }

            $this->log->error("MNotify SMS response parsing error: " . $e->getMessage());
            throw new Error("MNotify SMS sending error. Invalid response format.");
        }
    }

    private function processError(int $code, string $body): void
    {
        try {
            $data = Json::decode($body);

            $message = $data->message ?? null;
        }
        catch (Throwable $e) {
            $message = null;
        }

        if ($message) {
            $this->log->error("MNotify SMS sending error. Code: {$code}, Message: " . $message);
            throw new Error("MNotify SMS sending error: {$message}");
        }

        $this->log->error("MNotify SMS sending error. HTTP Code: {$code}");
        throw new Error("MNotify SMS sending error. Code: {$code}.");
    }

    private function getIntegrationEntity(): Integration
    {
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'MNotify');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("MNotify integration is not enabled");
        }

        return $entity;
    }
}
