<?php

namespace Bitpay\BPCheckout\Model;

use Magento\Sales\Model\Order;

class IpnManagement implements \Bitpay\BPCheckout\Api\IpnManagementInterface
{

    /**
     * {@inheritdoc}
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\ResponseFactory $responseFactory,
        \Magento\Framework\UrlInterface $url,
        \Magento\Framework\Module\ModuleListInterface $moduleList
    ) {
        $this->_moduleList = $moduleList;

        $this->_scopeConfig = $scopeConfig;
        $this->_responseFactory = $responseFactory;
        $this->_url = $url;

    }
    public function getStoreConfig($_env)
    {
        $_val = $this->_scopeConfig->getValue(
            $_env, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        return $_val;

    }

    public function getOrder($_order_id)
    {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->create('Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($_order_id);

        return $order;

    }
    public function postIpn()
    {

        #database
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $table_name = $resource->getTableName('bitpay_transactions');
        #json ipn
        $all_data = json_decode(file_get_contents("php://input"), true);
        $data = $all_data['data'];
        $event = $all_data['event'];

        $orderid = $data['orderId'];
        $order_status = $data['status'];
        $order_invoice = $data['id'];

        #is it in the lookup table
        $sql = "SELECT * FROM $table_name WHERE order_id = '$orderid' AND transaction_id = '$order_invoice' ";

        $result = $connection->query($sql);
        $row = $result->fetch();
        if ($row):

            $path = $_SERVER['DOCUMENT_ROOT'] . '/app/code/Bitpay/BPCheckout/';
            include $path . 'BitPayLib/BPC_Client.php';
            include $path . 'BitPayLib/BPC_Configuration.php';
            include $path . 'BitPayLib/BPC_Invoice.php';
            include $path . 'BitPayLib/BPC_Item.php';



            #verify the ipn
            $env = $this->getStoreConfig('payment/bpcheckout/bitpay_endpoint');
            $bitpay_token = $this->getStoreConfig('payment/bpcheckout/bitpay_devtoken');
            if ($env == 'prod'):
                $bitpay_token = $this->getStoreConfig('payment/bpcheckout/bitpay_prodtoken');
            endif;

            $config = (new \Bitpay\BPCheckout\BitPayLib\BPC_Configuration($bitpay_token, $env));
            $params = (new \stdClass());

            $params->invoiceID = $order_invoice;
            $params->extension_version = $this->getExtensionVersion();

            $item = (new \Bitpay\BPCheckout\BitPayLib\BPC_Item($config, $params));
            $invoice = (new \Bitpay\BPCheckout\BitPayLib\BPC_Invoice($item));

            $orderStatus = json_decode($invoice->BPC_checkInvoiceStatus($order_invoice));
            $invoice_status = $orderStatus->data->status;
            $update_sql = "UPDATE $table_name SET transaction_status = '$invoice_status' WHERE order_id = '$orderid' AND transaction_id = '$order_invoice'";

            $update_result = $connection->query($update_sql);

            $order = $this->getOrder($orderid);
            #now update the order
            switch ($event['name']) {
                case 'invoice_confirmed':

                    $order->addStatusHistoryComment('BitPay Invoice <a href = "http://' . $item->endpoint . '/dashboard/payments/' . $order_invoice . '" target = "_blank">' . $order_invoice . '</a> processing has been completed.');
                    $order->setState(Order::STATE_COMPLETE)->setStatus(Order::STATE_COMPLETE);
                    $order->save();
                    return true;
                    break;

                case 'invoice_paidInFull':

                    $order->addStatusHistoryComment('BitPay Invoice <a href = "http://' . $item->endpoint . '/dashboard/payments/' . $order_invoice . '" target = "_blank">' . $order_invoice . '</a> is processing.');
                    $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                    $order->save();
                    return true;

                    break;

                case 'invoice_failedToConfirm':

                    $order->addStatusHistoryComment('BitPay Invoice <a href = "http://' . $item->endpoint . '/dashboard/payments/' . $order_invoice . '" target = "_blank">' . $order_invoice . '</a> has become invalid because of network congestion.  Order will automatically update when the status changes.');
                    $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
                    $order->save();
                    return true;
                    break;

                case 'invoice_expired':
                    $order->delete();

                    return true;
                    break;

                case 'invoice_refundComplete':
                    #load the order to update

                    $order->addStatusHistoryComment('BitPay Invoice <a href = "http://' . $item->endpoint . '/dashboard/payments/' . $order_invoice . '" target = "_blank">' . $order_invoice . '</a> has been refunded.');
                    $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);

                    $order->save();

                    return true;
                    break;
            }

        endif;
    }
    public function getExtensionVersion()
    {
        $moduleCode = 'Bitpay_BPCheckout'; #Edit here with your Namespace_Module
        $moduleInfo = $this->_moduleList->getOne($moduleCode);
        return 'Magento2_2.0';
    }
}
