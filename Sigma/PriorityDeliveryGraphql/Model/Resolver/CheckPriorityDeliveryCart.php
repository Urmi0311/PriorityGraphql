<?php

namespace Sigma\PriorityDeliveryGraphql\Model\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Api\Data\ProductInterface;

class CheckPriorityDeliveryCart implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;
    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Mask Id
     *
     * @var QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;

    /**
     * CheckPriorityDeliveryCart constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param CartRepositoryInterface    $cartRepository
     * @param LoggerInterface            $logger
     * @param ScopeConfigInterface       $scopeConfig
     * @param QuoteIdMaskFactory         $quoteIdMaskFactory
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        CartRepositoryInterface    $cartRepository,
        LoggerInterface            $logger,
        ScopeConfigInterface       $scopeConfig,
        QuoteIdMaskFactory         $quoteIdMaskFactory
    ) {
        $this->productRepository = $productRepository;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $cartId = $args['cart_id'];
        $this->logger->info("Checking priority delivery for cart: $cartId");
        try {
            $cart = $this->loadCart($cartId);
            $cartItems = $cart->getAllVisibleItems();

            foreach ($cartItems as $cartItem) {
                $this->logger->info("Cart Item: " . $cartItem->getName());
                    $product = $this->productRepository->getById($cartItem->getProductId());
                $priorityEnabled = $this->isPriorityDeliveryEnabled($product);
                $toolKitValue = $priorityEnabled ? $this->scopeConfig->
                getValue('priority_delivery/priority_delivery_disable_time/tool_tip') : null;
            }
            return [
                'priorityEnabled' => $priorityEnabled,
                'toolkit' => $toolKitValue
            ];
        } catch (\Exception $e) {
            $this->logger->error("An error occurred while checking priority
            delivery for cart $cartId: " . $e->getMessage());
        }
    }

    /**
     * Load cart by its ID.
     *
     * @param int|string $cartId The ID of the cart to load.
     * @return \Magento\Quote\Model\Quote The loaded cart.
     */
    protected function loadCart($cartId)
    {
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $cartId = $quoteIdMask->getQuoteId();
        return $this->cartRepository->get($cartId);
    }

    /**
     * Check if Priority Delivery is enabled for the product
     *
     * @param ProductInterface $product
     * @return bool
     */
    protected function isPriorityDeliveryEnabled(ProductInterface $product): bool
    {

        $priority = $product->getData('priority_shipping');
        $this->logger->info("Priority value:" . $priority);

        $currentDayOfWeek = date('w');
        $currentTime = strtotime(date('H:i'));

        $this->logger->info("Current day: $currentDayOfWeek");
        $this->logger->info("Current time: " . date('H:i'));

        $fromWeekdays = explode(',', $this->
        scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/from_weekdays'));
        $toWeekdays = explode(',', $this->
        scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/to_weekdays'));

        $this->logger->info("Fetched From Weekdays: " . implode(', ', $fromWeekdays));
        $this->logger->info("Fetched To Weekdays: " . implode(', ', $toWeekdays));

        $fromTimeStr = $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/from_time');
        $toTimeStr = $this->scopeConfig->getValue('priority_delivery/priority_delivery_disable_time/to_time');

        $this->logger->info("Fetched From Time: $fromTimeStr");
        $this->logger->info("Fetched To Time: $toTimeStr");

        $fromTimeParts = explode(',', $fromTimeStr);
        $toTimeParts = explode(',', $toTimeStr);

        $fromTimeFormatted = sprintf('%02d:%02d', $fromTimeParts[0], $fromTimeParts[1]);
        $toTimeFormatted = sprintf('%02d:%02d', $toTimeParts[0], $toTimeParts[1]);

        $fromTime = strtotime($fromTimeFormatted);
        $toTime = strtotime($toTimeFormatted);

        $this->logger->info("Configured range: From " . date('H:i', $fromTime) .
            " to " . date('H:i', $toTime) . " on " . implode(',', $fromWeekdays));

        if ($priority == 0 && in_array($currentDayOfWeek, $fromWeekdays) &&
            in_array($currentDayOfWeek, $toWeekdays) && $currentTime == $fromTime
            && $currentTime == $toTime) {
            return false;
        }

        return true;
    }
}
