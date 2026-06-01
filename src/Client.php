<?php

declare(strict_types=1);

namespace Callisto\Sdk;

use Callisto\Sdk\Http\Transport;
use Callisto\Sdk\Resource\Balance;
use Callisto\Sdk\Resource\Notify;
use Callisto\Sdk\Resource\Otp;
use Callisto\Sdk\Resource\Sms;
use Callisto\Sdk\Resource\WhatsApp;
use GuzzleHttp\Client as GuzzleClient;

final class Client
{
    private readonly Transport $transport;
    private ?Balance $balance = null;
    private ?Sms $sms = null;
    private ?Otp $otp = null;
    private ?WhatsApp $whatsApp = null;
    private ?Notify $notify = null;

    public function __construct(
        ?string $clientId = null,
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        ?GuzzleClient $httpClient = null,
    ) {
        $config = Config::resolve($clientId, $apiKey, $baseUrl, $timeout);
        $this->transport = new Transport($config, $httpClient);
    }

    public function balance(): Balance
    {
        return $this->balance ??= new Balance($this->transport);
    }

    public function sms(): Sms
    {
        return $this->sms ??= new Sms($this->transport);
    }

    public function otp(): Otp
    {
        return $this->otp ??= new Otp($this->transport);
    }

    public function whatsApp(): WhatsApp
    {
        return $this->whatsApp ??= new WhatsApp($this->transport);
    }

    public function notify(): Notify
    {
        return $this->notify ??= new Notify($this->transport);
    }
}
