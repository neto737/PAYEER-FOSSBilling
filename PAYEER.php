<?php

class Payment_Adapter_PAYEER implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private array $config = [];

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
        foreach (['merchant_id', 'secret_key'] as $key) {
            if (!isset($this->config[$key])) {
                throw new \Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PAYEER', ':missing' => $key], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description'                => 'PAYEER Payment Gateway',
            'logo'                       => [
                'logo'   => '/PAYEER/payeer-logo.png',
                'height' => '50px',
                'width'  => '50px',
            ],
            'form'                       => [
                'merchant_id' => [
                    'text',
                    [
                        'label' => 'Merchant ID:',
                    ],
                ],
                'secret_key'  => [
                    'text',
                    [
                        'label' => 'Secret key:',
                    ],
                ]
            ]
        ];
    }

    public function getHtml($api_admin, $invoice_id): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $post = $data['post'];

        if ($post['m_operation_id'] && $post['m_sign']) {
            $arHash = [
                $post['m_operation_id'],
                $post['m_operation_ps'],
                $post['m_operation_date'],
                $post['m_operation_pay_date'],
                $post['m_shop'],
                $post['m_orderid'],
                $post['m_amount'],
                $post['m_curr'],
                $post['m_desc'],
                $post['m_status']
            ];

            if (isset($post['m_params'])) {
                $arHash[] = $post['m_params'];
            }

            $arHash[] = $this->config['secret_key'];

            $sign_hash = strtoupper(hash('sha256', implode(':', $arHash)));

            if ($this->isIpnDuplicate($post)) {
                echo $post['m_orderid'].'|success';
                return;
            }

            if ($post['m_sign'] === $sign_hash && $post['m_status'] === 'success') {
                $invoice = $this->di['db']->getExistingModelById('Invoice', $post['m_orderid']);

                $transaction = $this->di['db']->dispense('Transaction');
                $transaction->invoice_id = $invoice->id;
                $transaction->gateway_id = $invoice->gateway_id;
                $transaction->txn_id = $post['m_operation_id'];
                $transaction->txn_status = $post['m_status'];
                $transaction->amount = $post['m_amount'];
                $transaction->currency = $post['m_curr'];

                $bd = [
                    'amount'      => $transaction->amount,
                    'description' => 'PAYEER transaction '.$post['m_operation_id'],
                    'type'        => 'transaction',
                    'rel_id'      => $transaction->id,
                ];

                $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
                $clientService = $this->di['mod_service']('client');
                $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

                $invoiceService = $this->di['mod_service']('Invoice');
                if ($transaction->invoice_id) {
                    $invoiceService->payInvoiceWithCredits($invoice);
                } else {
                    $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
                }

                $transaction->status = $post['m_status'];
                $transaction->ipn = json_encode($post);
                $transaction->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($transaction);

                echo $post['m_orderid'].'|success';
                return;
            }

            echo $post['m_orderid'].'|error';
            return;
        }
    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        $invoice_number_padding = $this->di['mod_service']('System')->getParamValue('invoice_number_padding');
        $invoice_number_padding = $invoice_number_padding !== null && $invoice_number_padding !== '' ? $invoice_number_padding : 5;

        $payeer['m_shop'] = $this->config['merchant_id'];
        $payeer['m_orderid'] = $invoice->id;
        $payeer['m_amount'] = $invoiceService->getTotalWithTax($invoice);
        $payeer['m_curr'] = 'USD';
        $payeer['m_desc'] = base64_encode('Order #'.$invoice->serie.sprintf('%0'.$invoice_number_padding.'s', $invoice->nr));
        $payeer['m_key'] = $this->config['secret_key'];

        $arHash = [
            $payeer['m_shop'],
            $payeer['m_orderid'],
            $payeer['m_amount'],
            $payeer['m_curr'],
            $payeer['m_desc'],
            $payeer['m_key']
        ];

        $payeer['m_sign'] = strtoupper(hash('sha256', implode(':', $arHash)));
        $payeer['m_process'] = 'send';

        // Remove key before building HTTP query
        unset($payeer['m_key']);

        $url = sprintf('https://payeer.com/merchant/?%s', http_build_query($payeer));

        return '<script type="text/javascript">window.location = "'.$url.'";</script>';
    }

    public function isIpnDuplicate(array $ipn): bool
    {
        $transaction = $this->di['db']->findOne('Transaction', 'txn_id = :txn_id and amount = :amount', [
            ':txn_id' => $ipn['m_operation_id'],
            ':amount' => $ipn['m_amount']
        ]);
        if ($transaction) {
            return $transaction->status == $ipn['m_status'];
        }
        return false;
    }
}
