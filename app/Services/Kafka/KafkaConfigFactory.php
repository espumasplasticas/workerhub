<?php

namespace App\Services\Kafka;

use longlang\phpkafka\Consumer\ConsumerConfig;
use longlang\phpkafka\Producer\ProducerConfig;

class KafkaConfigFactory
{
    public function makeProducerConfig(): ProducerConfig
    {
        $config = new ProducerConfig();
        $config->setClientId((string) config('workerhub.kafka.client_id'));
        $config->setBootstrapServers((string) config('workerhub.kafka.brokers'));
        $config->setConnectTimeout((float) config('workerhub.kafka.connect_timeout'));
        $config->setSendTimeout((float) config('workerhub.kafka.send_timeout'));
        $config->setRecvTimeout((float) config('workerhub.kafka.recv_timeout'));
        $config->setProduceRetry((int) config('workerhub.kafka.produce_retry'));
        $config->setProduceRetrySleep((float) config('workerhub.kafka.produce_retry_sleep'));
        $config->setAcks(1);

        return $config;
    }

    public function makeConsumerConfig(string $topic): ConsumerConfig
    {
        $config = new ConsumerConfig();
        $config->setClientId((string) config('workerhub.kafka.client_id'));
        $config->setBootstrapServer((string) config('workerhub.kafka.brokers'));
        $config->setTopic($topic);
        $config->setGroupId((string) config('workerhub.kafka.consumer_group'));
        $config->setConnectTimeout((float) config('workerhub.kafka.connect_timeout'));
        $config->setSendTimeout((float) config('workerhub.kafka.send_timeout'));
        $config->setRecvTimeout((float) config('workerhub.kafka.recv_timeout'));
        $config->setInterval((float) config('workerhub.kafka.consumer_interval'));
        $config->setSessionTimeout((float) config('workerhub.kafka.consumer_session_timeout'));
        $config->setRebalanceTimeout((float) config('workerhub.kafka.consumer_rebalance_timeout'));
        $config->setGroupHeartbeat((float) config('workerhub.kafka.consumer_heartbeat'));
        $config->setAutoCommit(false);

        return $config;
    }
}
