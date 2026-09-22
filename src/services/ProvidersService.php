<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\events\RegisterComponentTypesEvent;
use viesrood\mybooks\base\ProviderInterface;
use viesrood\mybooks\providers\Hardcover;
use viesrood\mybooks\providers\Manual;
use viesrood\mybooks\providers\OpenLibrary;
use yii\base\Component;

/**
 * The registry of book services.
 *
 * Built lazily on first use, never in a constructor: registering or loading
 * a provider can therefore never break a page that does not need one.
 */
class ProvidersService extends Component
{
    /**
     * Add your own provider classes to $event->types:
     *
     * Event::on(ProvidersService::class, ProvidersService::EVENT_REGISTER_PROVIDERS,
     *     fn(RegisterComponentTypesEvent $e) => $e->types[] = MyProvider::class);
     */
    public const EVENT_REGISTER_PROVIDERS = 'registerProviders';

    /** @var array<string, ProviderInterface>|null */
    private ?array $providers = null;

    /**
     * @return array<string, ProviderInterface> Keyed by handle.
     */
    public function getAllProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [OpenLibrary::class, Hardcover::class, Manual::class],
        ]);

        if ($this->hasEventHandlers(self::EVENT_REGISTER_PROVIDERS)) {
            $this->trigger(self::EVENT_REGISTER_PROVIDERS, $event);
        }

        $this->providers = [];

        foreach ($event->types as $class) {
            if (!is_string($class) || !is_subclass_of($class, ProviderInterface::class)) {
                // A broken third-party registration is logged, not fatal.
                Craft::warning(sprintf('Ignoring book provider %s: it does not implement ProviderInterface.', is_string($class) ? $class : gettype($class)), 'mybooks');
                continue;
            }

            /** @var ProviderInterface $provider */
            $provider = new $class();
            $this->providers[$provider::handle()] = $provider;
        }

        return $this->providers;
    }

    public function getProvider(string $handle): ?ProviderInterface
    {
        return $this->getAllProviders()[$handle] ?? null;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getProviderOptions(): array
    {
        $options = [];

        foreach ($this->getAllProviders() as $handle => $provider) {
            $options[] = ['label' => $provider::displayName(), 'value' => $handle];
        }

        return $options;
    }
}
