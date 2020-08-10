<?php
namespace Klaviyo\Reclaim\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class PlacedOrderObserver implements ObserverInterface
{

    protected $_dataHelper;
    protected $_klaviyoScopeSetting;

    public function __construct(
        \Klaviyo\Reclaim\Helper\Data $_dataHelper,
        \Klaviyo\Reclaim\Helper\ScopeSetting $_klaviyoScopeSetting
    ) {
        $this->_dataHelper = $_dataHelper;
        $this->_klaviyoScopeSetting = $_klaviyoScopeSetting;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->_klaviyoScopeSetting->isEnabled() || !$this->_klaviyoScopeSetting->isWebhookEnabled()) return;

        $this->_dataHelper->sendOrderToKlaviyo(
            $observer->getEvent()->getOrder()
        );
    }
}
