<?php declare(strict_types=1);

namespace JovepayPlugin;

use JovepayPlugin\Service\Jovepay;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
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
use Shopware\Core\Framework\Uuid\Uuid;

class JovepayPlugin extends Plugin
{
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
        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
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
        if ($this->getPaymentMethodId()) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(\get_class($this), $context);

        // technicalName is a write-protected field requiring system scope in Shopware 6.5+
        $systemContext = new Context(new SystemSource());

        $jovepayMethodData = [
            'handlerIdentifier' => Jovepay::class,
            'name' => 'JOVEpay Crypto Payments',
            'description' => 'Pay in 100+ cryptocurrencies with JOVEpay',
            'technicalName' => 'payment_jovepay_jovepay',
            'pluginId' => $pluginId,
            'position' => 1,
            'active' => false,
            'afterOrderEnabled' => true,
            'mediaId' => $this->createPaymentLogo($systemContext),
        ];

        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$jovepayMethodData], $systemContext);
    }

    private function enablePaymentMethodForAllSalesChannels(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId();
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

    private function createPaymentLogo(Context $context): string
    {
        $mediaId = Uuid::randomHex();

        $mediaRepository = $this->container->get('media.repository');

        $mediaRepository->create([
            [
                'id' => $mediaId,
                'name' => 'jovepay-payment-logo',
                'mimeType' => 'image/svg+xml',
                'fileExtension' => 'svg',
            ]
        ], $context);

        /** @var FileSaver $fileSaver */
        $fileSaver = $this->container->get(FileSaver::class);

        $filePath = __DIR__ . '/Resources/public/logo.svg';

        $mediaFile = new MediaFile(
            $filePath,
            'image/svg+xml',
            'svg',
            filesize($filePath)
        );
        $fileSaver->persistFileToMedia(
            $mediaFile,
            'logo.svg',
            $mediaId,
            $context
        );

        return $mediaId;
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId();

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

    private function getPaymentMethodId(): ?string
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', Jovepay::class));
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        return $paymentIds->firstId();
    }
}
