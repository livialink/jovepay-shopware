<?php declare(strict_types=1);

namespace JovepayPlugin;

use JovepayPlugin\Service\Jovepay;
use JovepayPlugin\Subscriber\RouteScopeCompatSubscriber;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class JovepayPlugin extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Guarantee registration on Shopware 6.2 even if YAML service wiring is skipped.
        if (!$container->hasDefinition(RouteScopeCompatSubscriber::class)) {
            $container->register(RouteScopeCompatSubscriber::class, RouteScopeCompatSubscriber::class)
                ->addTag('kernel.event_subscriber')
                ->setPublic(true);
        }
    }

    public function install(InstallContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
        $this->enablePaymentMethodForAllSalesChannels($context->getContext());
    }

    public function update(UpdateContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
        $this->enablePaymentMethodForAllSalesChannels($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        // Keep the payment method entity for order history consistency; only deactivate it.
        // Shopware core removes plugin configuration when keepUserData() is false.
        $this->setPaymentMethodIsActive(false, $context->getContext());
    }

    public function activate(ActivateContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
        $this->setPaymentMethodIsActive(true, $context->getContext());
        $this->enablePaymentMethodForAllSalesChannels($context->getContext());
        parent::activate($context);
    }

    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    private function addPaymentMethod(Context $context): void
    {
        if ($this->getPaymentMethodId($context)) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        // technicalName is a write-protected field requiring system scope in Shopware 6.5+
        $systemContext = new Context(new SystemSource());

        $jovepayMethodData = [
            'handlerIdentifier' => Jovepay::class,
            'name' => 'JOVEpay Crypto Payments',
            'description' => 'Pay in 100+ cryptocurrencies with JOVEpay',
            'pluginId' => $pluginId,
            'position' => 1,
            'active' => false,
        ];

        // technicalName + afterOrderEnabled exist from Shopware 6.4/6.5 onward.
        if ($this->supportsPaymentTechnicalName()) {
            $jovepayMethodData['technicalName'] = 'payment_jovepay_jovepay';
            $jovepayMethodData['afterOrderEnabled'] = true;
        }

        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$jovepayMethodData], $systemContext);
    }

    private function enablePaymentMethodForAllSalesChannels(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($context);
        if (!$paymentMethodId) {
            return;
        }

        $salesChannelRepository = $this->container->get('sales_channel.repository');
        $salesChannelIds = $salesChannelRepository->searchIds(new Criteria(), $context)->getIds();

        if ($salesChannelIds === []) {
            return;
        }

        $paymentMethodSalesChannelRepository = $this->container->get('sales_channel_payment_method.repository');

        $payload = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $payload[] = [
                'salesChannelId' => $salesChannelId,
                'paymentMethodId' => $paymentMethodId,
            ];
        }

        $paymentMethodSalesChannelRepository->upsert($payload, $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId($context);

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    private function getPaymentMethodId(Context $context): ?string
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', Jovepay::class));
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, $context);

        return $paymentIds->firstId();
    }

    private function supportsPaymentTechnicalName(): bool
    {
        $version = '0.0.0';
        if ($this->container->hasParameter('kernel.shopware_version')) {
            $version = (string) $this->container->getParameter('kernel.shopware_version');
        }

        return \version_compare($version, '6.4.0', '>=');
    }
}
