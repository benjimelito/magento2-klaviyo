<?php

namespace Klaviyo\Reclaim\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class UserProfileNewsletterSubscribeObserver implements ObserverInterface
{
    protected $_dataHelper;
    protected $customer_repository_interface;

    public function __construct(
        \Klaviyo\Reclaim\Helper\Data $_dataHelper,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Customer\Api\CustomerRepositoryInterface $customer_repository_interface
    ) {
        $this->_dataHelper = $_dataHelper;
        $this->request = $request;
        $this->customer_repository_interface = $customer_repository_interface;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->_dataHelper->getEnabled()) return;

        $subscriber = $observer->getDataObject();

        if ($subscriber->isStatusChanged()) {
          $customer = $this->customer_repository_interface->getById($subscriber->getCustomerId());

          if ($subscriber->isSubscribed()) {
            $this->_dataHelper->subscribeEmailToKlaviyoList(
                $customer->getEmail(),
                $customer->getFirstname(),
                $customer->getLastname()
            );
          } else {
            $this->_dataHelper->unsubscribeEmailFromKlaviyoList($customer->getEmail());
          }
        }
    }
}
