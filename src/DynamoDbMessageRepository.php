<?php

namespace Brito\DynamoDbMessageRepository;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\ResultPaginator;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use EventSauce\EventSourcing\Header;
use Ramsey\Uuid\Uuid;
use Generator;

class DynamoDbMessageRepository implements MessageRepository
{
    /**
     * @var DynamoDbClient
     */
    protected $client;

    /**
     * @var MessageSerializer
     */
    protected $serializer;

    /**
     * @var string
     */
    protected $tableName;

    public function __construct(DynamoDbClient $client, MessageSerializer $serializer, string $tableName)
    {
        $this->client = $client;
        $this->serializer = $serializer;
        $this->tableName = $tableName;
    }

    public function persist(Message ...$messages)
    {
        if (count($messages) === 0) {
            return;
        }

        $items = [];
        $marshaler = new Marshaler();

        foreach ($messages as $index => $message) {
            $payload = $this->serializer->serializeMessage($message);
            $event = $payload['headers'][Header::EVENT_ID] = $payload['headers'][Header::EVENT_ID] ?? Uuid::uuid4()->toString();
            $aggregateRootId = $payload['headers'][Header::AGGREGATE_ROOT_ID] ?? null;
            $eventType = $payload['headers'][Header::EVENT_TYPE] ?? null;
            $aggregateRootVersion = $payload['headers'][Header::AGGREGATE_ROOT_VERSION] ?? 0;
            $timeOfRecording = $payload['headers'][Header::TIME_OF_RECORDING];

            $item = [
                'event' => $marshaler->marshalValue($event),
                'aggregateRootId' => $marshaler->marshalValue($aggregateRootId),
                'eventType' => $marshaler->marshalValue($eventType),
                'aggregateRootVersion' => $marshaler->marshalValue($aggregateRootVersion),
                'timeOfRecording' => $marshaler->marshalValue($timeOfRecording),
                'payload' => $marshaler->marshalItem(['payload' => $payload])['payload']
            ];

            $items[] = [
                'PutRequest' => [
                    'Item' => $item
                ]
            ];
        }

        $batchRequest = ['RequestItems' => [ $this->tableName => $items ]];

        $this->client->batchWriteItem($batchRequest);

    }

    public function retrieveAll(AggregateRootId $id): Generator
    {
        $query = [
            'TableName' => $this->tableName,
            'KeyConditionExpression' => 'aggregateRootId = :v1',
            'ExpressionAttributeValues' => [
                ':v1' => ['S' => $id->toString()],
            ]
        ];

        $result = $this->client->getPaginator('Query', $query);

        return $this->yieldMessagesForResult($result);
    }

    public function retrieveEverything(): Generator
    {
        $result = $this->client->getPaginator('Scan', ['TableName' => $this->tableName]);

        return $this->yieldMessagesForResult($result);
    }

    public function retrieveAllAfterVersion(AggregateRootId $id, int $aggregateRootVersion): Generator
    {
        $query = [
            'TableName' => $this->tableName,
            'KeyConditionExpression' => 'aggregateRootId = :v1 AND aggregateRootVersion > :v2',
            'ExpressionAttributeValues' => [
                ':v1' => ['S' => $id->toString()],
                ':v2' => ['N' => $aggregateRootVersion]
            ]
        ];

        $result = $this->client->getPaginator('Query', $query);

        return $this->yieldMessagesForResult($result);
    }

    private function yieldMessagesForResult(ResultPaginator $result)
    {
        $marshaler = new Marshaler();
        $items = $result->search('Items');

        foreach ($items as $item) {
            $payloadItem = $marshaler->unmarshalItem($item);

            $message = $this->serializer->unserializePayload($payloadItem['payload']);

            yield $message->current();
        }

        return isset($message)
            ? $message->current()->header(Header::AGGREGATE_ROOT_VERSION) ?: 0
            : 0;
    }
}
