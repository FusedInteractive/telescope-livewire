<?php

namespace Fused\TelescopeLivewire;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

class LivewireWatcher extends RequestWatcher
{
    public function register($app)
    {
        $app['events']->listen(RequestHandled::class, [$this, 'recordRequest']);
    }

    public function recordRequest(RequestHandled $event): void
    {
        if (! Telescope::isRecording() || ! $this->checkForLivewireMeachanism($event)) {
            return;
        }

        $startTime = defined('LARAVEL_START') ? LARAVEL_START : $event->request->server('REQUEST_TIME_FLOAT');

        foreach ($event->request->json('components') as $component) {
            $calls = $component['calls'];
            $snapshot = json_decode($component['snapshot'], associative: true);
            [$component] = app(HandleComponents::class)->fromSnapshot($snapshot);

            foreach ($calls as $call) {
                Telescope::recordRequest(IncomingEntry::make([
                    'ip_address' => $event->request->ip(),
                    'uri' => '/'.$snapshot['memo']['path'],
                    'method' => $event->request->method(),
                    'controller_action' => get_class($component).'@'.$call['method'],
                    'middleware' => array_values(optional($event->request->route())->gatherMiddleware() ?? []),
                    'headers' => $this->headers($event->request->headers->all()),
                    'payload' => $call['params'] ?? [],
                    'session' => $this->payload($this->sessionVariables($event->request)),
                    'response_status' => $event->response->getStatusCode(),
                    'response' => $this->response($event->response),
                    'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
                    'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
                ]));
            }
        }
    }

    private function checkForLivewireMeachanism(RequestHandled $event): bool
    {
        return optional($event->request->route())->named('*livewire.*') == true;
    }

    private function sessionVariables(Request $request): array
    {
        return $request->hasSession() ? $request->session()->all() : [];
    }
}
