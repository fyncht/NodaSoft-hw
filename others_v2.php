<?php

namespace NW\WebService\References\Operations\Notification;

class Contractor
{
    const TYPE_CUSTOMER = 0;
    public $id;
    public $type;
    public $name;

    // Добавлен конструктор для инициализации объекта
    public function __construct(int $id = null, int $type = self::TYPE_CUSTOMER, string $name = '')
    {
        $this->id = $id;
        $this->type = $type;
        $this->name = $name;
    }

    public static function getById(int $id): ?self
    {
        // Имитация получения данных, в реальном случае следует получать данные из БД или другого источника
        // return null если объект не найден
    }

    public function getFullName(): string
    {
        return $this->name . ' ' . $this->id;
    }
}

class Seller extends Contractor {}
class Employee extends Contractor {}

class Status
{
    public static function getName(int $id): string
    {
        $statusNames = [
            0 => 'Completed',
            1 => 'Pending',
            2 => 'Rejected',
        ];

        return $statusNames[$id] ?? 'Unknown';
    }
}

abstract class ReferencesOperation
{
    abstract public function doOperation(): array;

    // Добавлена валидация и фильтрация данных
    public function getRequest(string $paramName)
    {
        return filter_input(INPUT_REQUEST, $paramName, FILTER_SANITIZE_SPECIAL_CHARS);
    }
}

// Использование аргументов функций
function getResellerEmailFrom(int $resellerId): string
{
    // Логика для получения email реселлера по его ID
    return 'contractor@example.com';
}

function getEmailsByPermit(int $resellerId, string $event): array
{
    // Логика для получения списка email по разрешениям и событию
    return ['someemail@example.com', 'someemail2@example.com'];
}

class NotificationEvents
{
    const CHANGE_RETURN_STATUS = 'changeReturnStatus';
    const NEW_RETURN_STATUS = 'newReturnStatus';
}
