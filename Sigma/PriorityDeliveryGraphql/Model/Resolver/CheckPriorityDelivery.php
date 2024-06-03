<?php

namespace Sigma\PriorityDeliveryGraphql\Model\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CheckPriorityDelivery implements ResolverInterface
{
    protected $productRepository;
    protected $logger;
    protected $scopeConfig;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        LoggerInterface            $logger,
        ScopeConfigInterface       $scopeConfig
    )
    {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field       $field,
                    $context,
        ResolveInfo $info,
        array       $value = null,
        array       $args = null
    )
    {
        $sku = $args['sku'];
        $this->logger->info("Checking priority delivery for SKU: $sku");

        try {
            $product = $this->productRepository->get($sku);
            $priorityEnabled = $this->isPriorityDeliveryEnabled($product);
            $toolKitValue = $priorityEnabled ? $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/tool_tip') : null;

            return [
                'priorityEnabled' => $priorityEnabled,
                'toolkit' => $toolKitValue
            ];
        } catch (NoSuchEntityException $e) {
            $this->logger->info("Product with SKU $sku not found.");
            throw new GraphQlInputException(__('Product with SKU %1 not found.', $sku));
        } catch (\Exception $e) {
            $this->logger->error("An error occurred while checking priority delivery for SKU $sku: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if Priority Delivery is enabled for the product
     * @param ProductInterface $product
     * @return bool
     */
    protected function isPriorityDeliveryEnabled(ProductInterface $product): bool
    {
        $priority = $product->getData('priority');
        $this->logger->info("Priority value: $priority");


        $currentDayOfWeek = date('w');
        $currentTime = strtotime(date('H:i'));

        $this->logger->info("Current day: $currentDayOfWeek");
        $this->logger->info("Current time: " . date('H:i'));

        $fromWeekdays = explode(',', $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/from_weekdays'));
        $toWeekdays = explode(',', $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/to_weekdays'));

        $this->logger->info("Fetched From Weekdays: " . implode(', ', $fromWeekdays));
        $this->logger->info("Fetched To Weekdays: " . implode(', ', $toWeekdays));

        $fromTimeStr = $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/from_time', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $toTimeStr = $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/to_time', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        $this->logger->info("Fetched From Time: $fromTimeStr");
        $this->logger->info("Fetched To Time: $toTimeStr");

        $fromTimeParts = explode(',', $fromTimeStr);
        $toTimeParts = explode(',', $toTimeStr);

        $fromTimeFormatted = sprintf('%02d:%02d:%02d', $fromTimeParts[0], $fromTimeParts[1], $fromTimeParts[2]);
        $toTimeFormatted = sprintf('%02d:%02d:%02d', $toTimeParts[0], $toTimeParts[1], $toTimeParts[2]);

        $fromTime = strtotime('TODAY ' . $fromTimeFormatted);
        $toTime = strtotime('TODAY ' . $toTimeFormatted);

        $this->logger->info("Configured range: From " . date('H:i', $fromTime) . " to " . date('H:i', $toTime) . " on " . implode(',', $fromWeekdays));

        if ($priority == 0 && in_array($currentDayOfWeek, $fromWeekdays) && in_array($currentDayOfWeek, $toWeekdays) && $currentTime >= $fromTime && $currentTime <= $toTime) {
            return false;
        }
        return true;
    }

}
