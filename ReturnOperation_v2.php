<?php

namespace NW\WebService\References\Operations\Notification;

use NW\WebService\Models\{Seller, Contractor, Employee, Status};
use NW\WebService\Utilities\{MessagesClient, NotificationManager};

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    public function doOperation(): array
    {
        $data = $this->validateAndCastData($this->getRequest('data'));
        $resellerId = $data['resellerId'];
        $notificationType = $data['notificationType'];

        // Initializing result structure.
        $result = $this->initializeResult();

        // Loading necessary entities and validate them.
        $reseller = $this->loadEntity(Seller::class, $resellerId, 'Seller');
        $client = $this->loadEntity(Contractor::class, $data['clientId'], 'Client', $resellerId);
        $creator = $this->loadEntity(Employee::class, $data['creatorId'], 'Creator');
        $expert = $this->loadEntity(Employee::class, $data['expertId'], 'Expert');

        // Preparing data for template and validate it.
        $templateData = $this->prepareTemplateData($data, $client, $creator, $expert, $resellerId, $notificationType);
        $this->validateTemplateData($templateData);

        // Processing notifications based on the type.
        $this->processNotifications($notificationType, $resellerId, $client, $templateData, $result);

        return $result;
    }

    /**
     * Validating input data and cast necessary fields to their correct types.
     * 
     * @param mixed $requestData The raw request data.
     * @return array The validated and casted data.
     * @throws \InvalidArgumentException If critical data is missing.
     */
    private function validateAndCastData($requestData): array
    {
        $data = (array)$requestData;
        if (empty($data['resellerId']) || !isset($data['notificationType'])) {
            throw new \InvalidArgumentException('Reseller ID or Notification Type is missing.');
        }

        // Ensuring critical fields are of the correct type.
        $data['resellerId'] = (int)$data['resellerId'];
        $data['notificationType'] = (int)$data['notificationType'];

        return $data;
    }

    /**
     * Loading an entity by its ID and perform validations specific to the entity type.
     * 
     * @param string $class The entity class to load.
     * @param int $id The ID of the entity to load.
     * @param string $entityName The name of the entity for error messages.
     * @param int|null $resellerId Optional reseller ID for additional client validation.
     * @return mixed The loaded entity.
     * @throws \Exception If the entity is not found or does not meet the criteria.
     */
    private function loadEntity(string $class, int $id, string $entityName, ?int $resellerId = null)
    {
        $entity = $class::getById($id);
        if (!$entity) {
            throw new \Exception("{$entityName} not found!", 400);
        }

        // Additional validation for clients to ensure they belong to the correct reseller.
        if ($entityName === 'Client' && ($entity->type !== Contractor::TYPE_CUSTOMER || $entity->Seller->id !== $resellerId)) {
            throw new \Exception('Client not found or does not belong to the reseller!', 400);
        }

        return $entity;
    }

    /**
     * Initializing the result structure with default values.
     * 
     * @return array The initialized result structure.
     */
    private function initializeResult(): array
    {
        return [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];
    }

    /**
     * Preparing template data for notifications.
     * 
     * @param array $data The validated request data.
     * @param Contractor $client The client entity.
     * @param Employee $creator The creator entity.
     * @param Employee $expert The expert entity.
     * @param int $resellerId The reseller ID.
     * @param int $notificationType The type of notification.
     * @return array The prepared template data.
     */
    private function prepareTemplateData(array $data, Contractor $client, Employee $creator, Employee $expert, int $resellerId, int $notificationType): array
    {
        $clientName = $client->getFullName() ?: $client->name;
        $differences = $this->determineDifferences($notificationType, $data, $resellerId);

        return [
            'COMPLAINT_ID' => $data['complaintId'],
            'COMPLAINT_NUMBER' => $data['complaintNumber'],
            'CREATOR_ID' => $creator->id,
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => $expert->id,
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => $client->id,
            'CLIENT_NAME' => $clientName,
            'CONSUMPTION_ID' => $data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER' => $data['agreementNumber'],
            'DATE' => $data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    /**
     * Determining the differences string based on notification type and data.
     * 
     * @param int $notificationType The notification type.
     * @param array $data The validated request data.
     * @param int $resellerId The reseller ID.
     * @return string The differences string for the template.
     */
    private function determineDifferences(int $notificationType, array $data, int $resellerId): string
    {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }

        return '';
    }

    /**
     * Validating the prepared template data to ensure no critical information is missing.
     * 
     * @param array $templateData The prepared template data.
     * @throws \Exception If any template data is missing.
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    /**
     * Processing and send out the notifications based on the notification type.
     * 
     * @param int $notificationType The notification type.
     * @param int $resellerId The reseller ID.
     * @param Contractor $client The client entity.
     * @param array $templateData The prepared template data.
     * @param array &$result The result structure to update with notification outcomes.
     */
    private function processNotifications(int $notificationType, int $resellerId, Contractor $client, array $templateData, array &$result): void
    {
        // Sending email notifications to employees.
        $this->sendEmployeeEmailNotifications($resellerId, $templateData, $result);

        // Sending notifications to the client if the type is TYPE_CHANGE.
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            $this->sendClientNotifications($resellerId, $client, $templateData, $result);
        }
    }

    /**
     * Sending email notifications to employees.
     * 
     * @param int $resellerId The reseller ID.
     * @param array $templateData The prepared template data.
     * @param array &$result The result structure to update with notification outcomes.
     */
    private function sendEmployeeEmailNotifications(int $resellerId, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if (!empty($emailFrom) && !empty($emails)) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    ['emailFrom' => $emailFrom, 'emailTo' => $email, 'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId), 'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId)],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    /**
     * Sending notifications to the client, both via email and SMS.
     * 
     * @param int $resellerId The reseller ID.
     * @param Contractor $client The client entity.
     * @param array $templateData The prepared template data.
     * @param array &$result The result structure to update with notification outcomes.
     */
    private function sendClientNotifications(int $resellerId, Contractor $client, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        if (!empty($emailFrom) && !empty($client->email)) {
            MessagesClient::sendMessage([
                ['emailFrom' => $emailFrom, 'emailTo' => $client->email, 'subject' => __('complaintClientEmailSubject', $templateData, $resellerId), 'message' => __('complaintClientEmailBody', $templateData, $resellerId)],
            ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS);
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($client->mobile)) {
            $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $templateData, $error);
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }
}
