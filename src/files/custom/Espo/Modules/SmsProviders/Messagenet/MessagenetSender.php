<?php
/************************************************************************
 * This file is part of SMS Providers extension for EspoCRM.
 *
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

namespace Espo\Modules\SmsProviders\Messagenet;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use Throwable;

class MessagenetSender implements Sender
{
    private const BASE_URL = 'https://api.messagenet.com/api';

    private const TIMEOUT = 10;

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

        foreach ($toNumberList as $number) {
            $this->sendToNumber($sms, $number);
        }
    }

    private function sendToNumber(Sms $sms, string $toNumber): void
    {
        $integration = $this->getIntegrationEntity();

        $userId = $integration->get('messagenetUserId');
        $password = $integration->get('messagenetPassword');

        $fromNumber = $sms->getFromNumber();

        $baseUrl = rtrim(
            $this->config->get('messagenetBaseUrl', self::BASE_URL)
        );

        $timeout = $this->config->get('messagenetSendTimeout', self::TIMEOUT);

        if (!$userId || !$password) {
            throw new Error("No Messagenet Credentials.");
        }

        if (!$toNumber) {
            throw new Error("No recipient phone number.");
        }

        $url = $baseUrl . '/send_sms?' .
            'auth_userid=' . $userId .
            '&auth_password=' . $password .
            '&destination=' . self::formatNumber($toNumber) .
            '&text=' . urlencode($sms->getBody()) .
            '&format=json';

        if ($fromNumber) {
            $url .= '&sender=' . self::formatNumber($fromNumber);
        }

        $ch = curl_init();

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_HEADER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'POST');

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        if ($code) {
            $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
            $body = mb_substr($response, $headerSize);

            $this->log->debug('Messagenet: {code} {body}', [
                'code' => $code,
                'body' => $body,
            ]);

            $data = Json::decode($body);

            try {
                $httpStatusCode = $data->http_status->value;
            } catch (Throwable $e) {
                $httpStatusCode = $code;
            }

            if (!($httpStatusCode >= 200 && $httpStatusCode < 300)) {
                $this->processError($httpStatusCode, $data);
            }
        }

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("Messagenet SMS sending timeout.");
            }
        }
    }

    private function getIntegrationEntity(): Integration
    {
        /** @var Integration $entity */
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'Messagenet');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("Messagenet integration is not enabled");
        }

        return $entity;
    }

    private static function formatNumber(string $number): string
    {
        return preg_replace('/[^0-9]/', '', $number);
    }

    private function processError(int $code, $data): void
    {
        try {
            $message = $data->status->description ?? null;
        } catch (Throwable $e) {
            $message = null;
        }

        if ($message) {
            $this->log->error("Messagenet SMS sending error. Message: " . $message);
        }

        throw new Error("Messagenet SMS sending error. Code: {$code}.");
    }
}
