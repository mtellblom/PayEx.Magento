<?php

class PayEx_MasterPass_Helper_Order extends Mage_Core_Helper_Abstract
{

    /**
     * Process Payment Transaction
     * @param Mage_Sales_Model_Order $order
     * @param array $fields
     *
     * @return Mage_Sales_Model_Order_Payment_Transaction|null
     * @throws Exception
     */
    public function processPaymentTransaction(Mage_Sales_Model_Order $order, array $fields)
    {
        // Lookup Transaction
        $collection = Mage::getModel('sales/order_payment_transaction')->getCollection()
            ->addAttributeToFilter('txn_id', $fields['transactionNumber']);
        if (count($collection) > 0) {
            Mage::helper('payex_mp/tools')->addToDebug(sprintf('Transaction %s already processed.', $fields['transactionNumber']), $order->getIncrementId());
            return $collection->getFirstItem();
        }

        // Set Payment Transaction Id
        $payment = $order->getPayment();
        $payment->setTransactionId($fields['transactionNumber']);

        /* Transaction statuses: 0=Sale, 1=Initialize, 2=Credit, 3=Authorize, 4=Cancel, 5=Failure, 6=Capture */
        $transaction_status = isset($fields['transactionStatus']) ? (int)$fields['transactionStatus'] : null;
        switch ($transaction_status) {
            case 1:
                // From PayEx PIM:
                // "If PxOrder.Complete returns transactionStatus = 1, then check pendingReason for status."
                // See http://www.payexpim.com/payment-methods/paypal/
                if ($fields['pending'] === 'true') {
                    $message = Mage::helper('payex_mp')->__('Transaction Status: %s.', $transaction_status);
                    $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, true, $message);
                    $transaction->setIsClosed(0);
                    $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                    $transaction->setMessage($message);
                    $transaction->save();
                    break;
                }

                $message = Mage::helper('payex_mp')->__('Transaction Status: %s.', $transaction_status);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, true, $message);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->setMessage($message);
                $transaction->save();
                break;
            case 3:
                $message = Mage::helper('payex_mp')->__('Transaction Status: %s.', $transaction_status);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, null, true, $message);
                $transaction->setIsClosed(0);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->setMessage($message);
                $transaction->save();
                break;
            case 0;
            case 6:
                $message = Mage::helper('payex_mp')->__('Transaction Status: %s.', $transaction_status);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, null, true, $message);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->isFailsafe(true)->close(false);
                $transaction->setMessage($message);
                $transaction->save();
                break;
            case 2:
                $message = Mage::helper('payex_mp')->__('Detected an abnormal payment process (Transaction Status: %s).', $transaction_status);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, true, $message);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->setMessage($message);
                $transaction->setIsCancel(true);
                $transaction->save();
                break;
            case 4;
                $message = Mage::helper('payex_mp')->__('Order automatically canceled. Transaction is canceled.');
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, true);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->setMessage($message);
                $transaction->setIsCancel(true);
                $transaction->save();
                break;
            case 5;
                $message = Mage::helper('payex_mp')->__('Order automatically canceled. Transaction is failed.');
                $message .= ' ' . Mage::helper('payex_mp/tools')->getVerboseErrorMessage($fields);
                $transaction = $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, true);
                $transaction->setAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $fields);
                $transaction->setMessage($message);
                $transaction->setIsCancel(true);
                $transaction->save();
                break;
            default:
                $message = Mage::helper('payex_mp')->__('Invalid transaction status.');
                $transaction = Mage::getModel('sales/order_payment_transaction');
                $transaction->setMessage($message);
                $transaction->setIsCancel(true);
                break;
        }

        try {
            $order->save();
            Mage::helper('payex_mp/tools')->addToDebug($message, $order->getIncrementId());
        } catch (Exception $e) {
            Mage::helper('payex_mp/tools')->addToDebug('Error: ' . $e->getMessage(), $order->getIncrementId());
        }

        return $transaction;
    }

    /**
     * Create Invoice
     * @param $order
     * @param bool $online
     * @return Mage_Sales_Model_Order_Invoice
     */
    public function makeInvoice(&$order, $online = false)
    {
        // Prepare Invoice
        $magento_version = Mage::getVersion();
        if (version_compare($magento_version, '1.4.2', '>=')) {
            $invoice = Mage::getModel('sales/order_invoice_api_v2');
            $invoice_id = $invoice->create($order->getIncrementId(), $order->getAllItems(), Mage::helper('payex_mp')->__('Auto-generated from PayEx module'), false, false);
            $invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoice_id);

            if ($online) {
                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                $invoice->capture()->save();
            } else {
                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                $invoice->pay()->save();
            }
        } else {
            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
            $invoice->addComment(Mage::helper('payex_mp')->__('Auto-generated from PayEx module'), false, false);
            $invoice->setRequestedCaptureCase($online ? Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE : Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
            $invoice->register();

            $invoice->getOrder()->setIsInProcess(true);

            try {
                $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();
            } catch (Mage_Core_Exception $e) {
                // Save Error Message
                $order->addStatusToHistory(
                    $order->getStatus(),
                    'Failed to create invoice: ' . $e->getMessage(),
                    true
                );
                Mage::throwException($e->getMessage());
            }
        }

        $invoice->setIsPaid(true);

        // Assign Last Transaction Id with Invoice
        $transactionId = $invoice->getOrder()->getPayment()->getLastTransId();
        if ($transactionId) {
            $invoice->setTransactionId($transactionId);
            $invoice->save();
        }

        return $invoice;
    }

    /**
     * Get First Transaction ID
     * @param  $order Mage_Sales_Model_Order
     * @return bool
     */
    static public function getFirstTransactionId(&$order)
    {
        $order_id = $order->getId();
        if (!$order_id) {
            return false;
        }
        $collection = Mage::getModel('sales/order_payment_transaction')->getCollection()
            ->addOrderIdFilter($order_id)
            ->setOrder('transaction_id', 'ASC')
            ->setPageSize(1)
            ->setCurPage(1);
        return $collection->getFirstItem()->getTxnId();
    }

    /**
     * Create CreditMemo
     * @param $order
     * @param $invoice
     * @param $amount
     * @param bool $online
     * @param null $transactionId
     * @return Mage_Sales_Model_Order_Creditmemo
     */
    public function makeCreditMemo(&$order, &$invoice, $amount, $online = false, $transactionId = null)
    {
        $service = Mage::getModel('sales/service_order', $order);

        // Prepare CreditMemo
        if ($invoice) {
            $creditmemo = $service->prepareInvoiceCreditmemo($invoice);
        } else {
            $creditmemo = $service->prepareCreditmemo();
        }
        $creditmemo->addComment(Mage::helper('payex_mp')->__('Auto-generated from PayEx module'));

        // Refund
        if (!$online) {
            $creditmemo->setPaymentRefundDisallowed(true);
        }
        //$creditmemo->setRefundRequested(true);
        $invoice->getOrder()->setBaseTotalRefunded(0);
        $creditmemo->setBaseGrandTotal($amount);
        $creditmemo->register()->refund();
        $creditmemo->save();

        // Add transaction Id
        if ($transactionId) {
            $creditmemo->setTransactionId($transactionId);
        }
        // Save CreditMemo
        $transactionSave = Mage::getModel('core/resource_transaction')
            ->addObject($creditmemo)
            ->addObject($creditmemo->getOrder());
        if ($creditmemo->getInvoice()) {
            $transactionSave->addObject($creditmemo->getInvoice());
        }
        $transactionSave->save();

        return $creditmemo;
    }

    /**
     * Calculate Order amount
     * With rounding issue detection
     * @param $order
     * @param int $order_amount
     * @return stdClass
     */
    public function getCalculatedOrderAmount($order, $order_amount = 0)
    {
        // Order amount calculated by shop
        if ($order_amount === 0) {
            $order_amount = $order->getGrandTotal();
        }

        // Order amount calculated manually
        $amount = 0;

        // add Order Items
        $items = $order->getAllVisibleItems();
        /** @var $item Mage_Sales_Model_Order_Item */
        foreach ($items as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            $itemQty = (int)$item->getQtyOrdered();
            $priceWithTax = $item->getPriceInclTax();
            $amount += (int)(100 * $itemQty * $priceWithTax);
        }

        // add Shipping
        if (!$order->getIsVirtual()) {
            $shippingIncTax = $order->getShippingInclTax();
            $amount += (int)(100 * $shippingIncTax);
        }

        // add Discount
        $discount = $order->getDiscountAmount() + $order->getShippingDiscountAmount();
        $amount += (int)(100 * $discount);

        // Add reward points
        $amount += -1 * (int)(100 * $order->getBaseRewardCurrencyAmount());

        // Detect Rounding Issue
        $rounded_total = sprintf("%.2f", $order_amount);
        $rounded_control_amount = sprintf("%.2f", ($amount / 100));
        $rounding = 0;
        if ($rounded_total !== $rounded_control_amount) {
            if ($rounded_total > $rounded_control_amount) {
                $rounding = $rounded_total - $rounded_control_amount;
            } else {
                $rounding = -1 * ($rounded_control_amount - $rounded_total);
            }
            $rounding = sprintf("%.2f", $rounding);
        }

        $result = new stdClass();
        $result->amount = $rounded_control_amount;
        $result->rounding = $rounding;
        return $result;
    }

    /**
     * Get Shopping Cart XML
     * @param Mage_Sales_Model_Quote $quote
     * @return string
     */
    public function getShoppingCartXML($quote)
    {
        $dom = new DOMDocument('1.0', 'utf-8');
        $ShoppingCart = $dom->createElement('ShoppingCart');
        $dom->appendChild($ShoppingCart);

        $ShoppingCart->appendChild($dom->createElement('CurrencyCode', $quote->getQuoteCurrencyCode()));
        $ShoppingCart->appendChild($dom->createElement('Subtotal', (int)(100 * $quote->getGrandTotal())));

        // Add Order Lines
        $items = $quote->getAllVisibleItems();
        /** @var $item Mage_Sales_Model_Quote_Item */
        foreach ($items as $item) {
            $product = $item->getProduct();

            $ShoppingCartItem = $dom->createElement('ShoppingCartItem');
            $ShoppingCartItem->appendChild($dom->createElement('Description', $item->getName()));
            $ShoppingCartItem->appendChild($dom->createElement('Quantity', $item->getQty()));
            $ShoppingCartItem->appendChild($dom->createElement('Value', (int)bcmul($product->getFinalPrice(), 100)));
            $ShoppingCartItem->appendChild($dom->createElement('ImageURL', $product->getThumbnailUrl()));
            $ShoppingCart->appendChild($ShoppingCartItem);
        }

        return str_replace("\n", '', $dom->saveXML());
    }
}