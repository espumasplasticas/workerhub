<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkerTaskConsumerSourceTest extends TestCase
{
    public function test_it_only_consumes_the_configured_request_topic_unless_explicitly_enabled(): void
    {
        $consumerSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\app\\Services\\Kafka\\WorkerTaskConsumer.php'
        );
        $configSource = file_get_contents(
            'c:\\laragon\\www\\WorkerHub\\config\\workerhub.php'
        );

        $this->assertIsString($consumerSource);
        $this->assertIsString($configSource);

        $this->assertStringContainsString("config('workerhub.kafka.consume_all_process_topics', false)", $consumerSource);
        $this->assertStringContainsString("\$configuredRequestTopic", $consumerSource);
        $this->assertStringContainsString("'consume_all_process_topics' => filter_var(env('WORKERHUB_KAFKA_CONSUME_ALL_PROCESS_TOPICS', false), FILTER_VALIDATE_BOOL)", $configSource);
    }
}
