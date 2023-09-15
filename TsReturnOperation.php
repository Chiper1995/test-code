<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = new ArrayHelper((array)$this->getRequest('data'));
        $resellerId = $data->getElement('resellerId');
        $notificationType = (int)$data->getElement('notificationType');

        if (empty($notificationType)) {
            throw new \Exception('Empty notificationType', 400);
        }

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        if (empty((int)$resellerId)) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        $reseller = Seller::getById((int)$resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }

        $client = Contractor::getById((int)$data->getElement('clientId'));
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('сlient not found!', 400);
        }

        $cFullName = $client->getFullName();
        if (empty($client->getFullName())) {
            $cFullName = $client->name;
        }

        $creator = Employee::getById((int)$data->getElement('creatorId'));
        if ($creator === null) {
            throw new \Exception('Creator not found!', 400);
        }

        $expert = Employee::getById((int)$data->getElement('expertId'));
        if ($expert === null) {
            throw new \Exception('Expert not found!', 400);
        }

        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data->getElement('differences'))) {
            $differencesArray = new ArrayHelper($data->getElement('differences'));

            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$differencesArray->getElement('from')),
                'TO'   => Status::getName((int)$differencesArray->getElement('to')),
            ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)$data->getElement('complaintId'),
            'COMPLAINT_NUMBER'   => (string)$data->getElement('complaintNumber'),
            'CREATOR_ID'         => (int)$data->getElement('creatorId'),
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => (int)$data->getElement('expertId'),
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => (int)$data->getElement('clientId'),
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$data->getElement('consumptionId'),
            'CONSUMPTION_NUMBER' => (string)$data->getElement('consumptionNumber'),
            'AGREEMENT_NUMBER'   => (string)$data->getElement('agreementNumber'),
            'DATE'               => (string)$data->getElement('date'),
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        $emailFrom = getResellerEmailFrom();
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);

                $result['notificationEmployeeByEmail'] = true;
            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $error = null;

                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}

class ArrayHelper
{
    protected array $array;

    public function __construct(array $array)
    {
        $this->array = $array;
    }

    public function getElement($key)
    {
        if (!array_key_exists($key, $this->array)) {
            throw new MissedArrayElementException($key);
        }

        return $this->array[$key];
    }
}

class MissedArrayElementException extends \Exception
{
    public function __construct($key)
    {
        parent::__construct("Key $key not found in array");
    }
}