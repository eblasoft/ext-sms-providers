<?php

namespace Espo\Modules\SmsProviders\Tools\Callback\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Log;

class Bandwidth implements Action
{

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(Request $request): Response
    {
        $this->log->info('Received Bandwidth callback.');

        $events = json_decode($request->getBodyContents());

        foreach ($events as $event) {
            switch ($event->type) {
                case 'message-received':
                    $this->log->info('Message received: ' . $event->message->id);

                    $sms = $this->entityManager->getNewEntity('Sms');
                    $time = $event->message->time;
                    $timestamp = strtotime($time);
                    $sms->set([
                        'name' => 'Inbound SMS from ' . $event->message->from,
                        'to' => $event->message->from,
                        'body' => $event->message->text,
                        'direction' => 'Inbound',
                        'status' => 'Received',
                        'externalId' => $event->message->id,
                        'dateSent' => date(DateTime::SYSTEM_DATE_TIME_FORMAT, $timestamp),
                    ]);

                    // Optional: Attach to a contact
                    $contact = $this->entityManager->getRDBRepository('Contact')
                        ->where([
                            'phoneNumber' => $event->message->from
                        ])
                        ->findOne();

                    if ($contact) {
                        $sms->set('parentType', 'Contact');
                        $sms->set('parentId', $contact->getId());
                    }

                    $this->entityManager->saveEntity($sms);
                    break;

                case 'message-read':
                    $this->log->info('Message read: ' . $event->message->id);

                    $sms = $this->entityManager->getRDBRepository('Sms')
                        ->where([
                            'externalId' => $event->message->id,
                        ])
                        ->findOne();

                    if ($sms) {
                        $sms->set('status', 'Received');
                        $this->entityManager->saveEntity($sms);
                        $this->log->info('Updated SMS status to received: ' . $sms->getId());
                    } else {
                        $this->log->warning('SMS not found for external ID: ' . $event->message->id);
                    }
                    break;

                case 'message-delivered':
                    $this->log->info('Message delivered: ' . $event->message->id);

                    $sms = $this->entityManager->getRDBRepository('Sms')
                        ->where([
                            'externalId' => $event->message->id,
                        ])
                        ->findOne();
                    if ($sms) {
                        $sms->set('status', 'Sent');
                        $this->entityManager->saveEntity($sms);
                        $this->log->info('Updated SMS status to delivered: ' . $sms->getId());
                    } else {
                        $this->log->warning('SMS not found for external ID: ' . $event->message->id);
                    }
                    break;

                case 'message-failed':
                    $this->log->error('Message failed: ' . $event->message->id . ' - ' . $event->errorCode);

                    $sms = $this->entityManager->getRDBRepository('Sms')
                        ->where([
                            'externalId' => $event->message->id,
                        ])
                        ->findOne();

                    if ($sms) {
                        $sms->set('status', 'Failed');
                        $this->entityManager->saveEntity($sms);
                        $this->log->info('Updated SMS status to failed: ' . $sms->getId());
                    } else {
                        $this->log->warning('SMS not found for external ID: ' . $event->message->id);
                    }
                    break;

                default:
                    $this->log->info('Unhandled event type: ' . $event->type);
            }
        }

        return ResponseComposer::json([
            'status' => 'success',
            'message' => 'Bandwidth callback processed successfully.'
        ]);
    }
}
