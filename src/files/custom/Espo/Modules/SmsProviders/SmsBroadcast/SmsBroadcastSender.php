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

namespace Espo\Modules\SmsProviders\SmsBroadcast;

use Espo\Core\Exceptions\Error;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;

class SmsBroadcastSender implements Sender
{
    private const BASE_URL = 'https://api.smsbroadcast.co.uk/api-adv.php';

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

        $username = $integration->get('smsBroadcastUsername');
        $password = $integration->get('smsBroadcastPassword');

        $fromNumber = $sms->getFromNumber();

        $baseUrl = rtrim(
            $this->config->get('smsBroadcastBaseUrl', self::BASE_URL)
        );

        $timeout = $this->config->get('smsBroadcastSendTimeout', self::TIMEOUT);

        if (!$username || !$password) {
            throw new Error("No SmsBroadcast Credentials.");
        }

        if (!$toNumber) {
            throw new Error("No recipient phone number.");
        }

        $content = 'username=' . rawurlencode($username) .
            '&password=' . rawurlencode($password) .
            '&to=' . rawurlencode(self::formatNumber($toNumber)) .
            '&message=' . rawurlencode($sms->getBody());

        if ($fromNumber) {
            $content .= '&from=' . rawurlencode($fromNumber);
        }

        $ch = curl_init();

        curl_setopt($ch, \CURLOPT_URL, $baseUrl);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $content);
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

            $this->log->debug('SmsBroadcast: {code} {body}', [
                'code' => $code,
                'body' => $body,
            ]);

            $message_data = explode(':', $body);
            if ($message_data[0] == "OK") {
                $this->log->debug('SmsBroadcast SMS sending successful. Reference: ' . $message_data[2]);
            } elseif ($message_data[0] == "BAD") {
                $this->log->error("SmsBroadcast SMS sending error. Reason: $message_data[1].");

                throw new Error("SmsBroadcast SMS sending error. Reason: $message_data[1].");
            } elseif ($message_data[0] == "ERROR") {
                $this->log->error("SmsBroadcast SMS sending error. Reason: $message_data[1].");

                throw new Error("SmsBroadcast SMS sending error. Reason: $message_data[1].");
            }
        }

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("SmsBroadcast SMS sending timeout.");
            }
        }
    }

    private function getIntegrationEntity(): Integration
    {
        /** @var Integration $entity */
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'SmsBroadcast');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("SmsBroadcast integration is not enabled");
        }

        return $entity;
    }

    private static function formatNumber(string $number): string
    {
        return preg_replace('/[^0-9]/', '', $number);
    }
}
