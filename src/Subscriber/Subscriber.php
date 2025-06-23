<?php declare( strict_types=1 );

namespace Promio\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use GuzzleHttp\Client;

class Subscriber
    implements EventSubscriberInterface
{

    private SystemConfigService $SystemConfigService;


    public function __construct( SystemConfigService $systemConfigService )
    {
        $this->SystemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            GenericPageLoadedEvent::class => 'onPageLoaded',
            AfterLineItemAddedEvent::class => 'onItemToCart',
            AfterLineItemRemovedEvent::class => 'onItemToCart',
            CartConvertedEvent::class => 'onOrder',
        ];
    }

    public function onItemToCart(  $m ): void
    {
        $cart = $m->getCart();
        $items = $cart->getLineItems();
        $itemList = array();

        foreach( $items as $item ) {
            $newItem = $this->shopItem2Pmp( $item );
            $newItem[ 'cartsession' ] = $cart->getToken();
            $itemList[] = $newItem;
        }

        $customerNumber = null;
        if( $m->getSalesChannelContext()
            && $m->getSalesChannelContext()
                ->getCustomer() ) {
            $customerNumber = $m->getSalesChannelContext()
                ->getCustomer()
                ->getCustomerNumber();
        }
        $this->sendCartHome( $itemList, $customerNumber );

    }

    protected function shopItem2Pmp( $item ): array
    {

        return array(
            'id' => $item->getPayloadValue( 'productNumber' ),
            'quantity' => $item->getQuantity(),
            'price' => $item->getPrice()
                ->getUnitPrice(),
            'tax' => $item->getPrice()
                ->getTaxRules()
                ->highestRate()
                ->getTaxRate(),
        );

    }

    protected function sendCartHome( $articleList, $customerId ): void
    {
        $pnUserData = $this->getPnTrackingData( $customerId );
        $payload = array(
            'pmp' => $pnUserData,
            'data' => array(
                'cart' => array(
                    'articles' => $articleList,
                ),
            ),
        );

        $requestCodeCart = $this->SystemConfigService->get( 'PromioShopConnector.config.RequestCodeCart' );
        if( empty( $requestCodeCart ) ) {
            return;
        }

        $client = new Client();
        $url = 'https://confirm.promio-connect.com/pmp.php?';
        $url .= 'a=' . $requestCodeCart;
        $url .= '&q=' . base64_encode( json_encode( $payload ) );
        #        var_dump($url);
        #        var_dump($payload);
        #        die();
        $res = $client->request( 'GET', $url );
    }

    protected function getPnTrackingData( $customerId ): array
    {
        $userData = array(
            'r' => '',
            'lid' => '',
            'ln' => '',
        );

        if( array_key_exists( 'pnData', $_COOKIE ) ) {
            $tracking = json_decode( base64_decode( $_COOKIE[ 'pnData' ] ) );
            if( is_object( $tracking ) ) {
                foreach( $userData as $key => $value ) {
                    if( isset( $tracking->$key ) ) {
                        $userData[ $key ] = $tracking->$key;
                    }
                }
            }
        }
        $userData[ 'custid' ] = $customerId;

        return $userData;
    }

    public function onOrder( CartConvertedEvent $event ): void
    {
        $convertedCart = $event->getConvertedCart();
        $lineItems = $event->getCart()
            ->getLineItems();
        $itemList = array();

        foreach( $lineItems as $item ) {
            $newItem = $this->shopItem2Pmp( $item );
            $newItem[ 'cartsession' ] = $event->getCart()
                ->getToken();
            $itemList[] = $newItem;
        }

        $this->sendOrderHome(
            $itemList,
            $convertedCart[ 'price' ]->getTotalPrice(),
            $convertedCart[ 'orderNumber' ],
            $convertedCart[ 'orderCustomer' ][ 'customerNumber' ]
        );

    }

    protected function sendOrderHome( $lineItems, $orderPrice, $orderId, $customerId ): void
    {
        $pnUserData = $this->getPnTrackingData( $customerId );
        $payload = array(
            'pmp' => $pnUserData,
            'data' => array(
                'order' => array(
                    'data' => array( 'orderid' => $orderId, 'userid' => $customerId, 'price' => $orderPrice, 'shipping' => 0 ),
                    'articles' => $lineItems,
                ),
            ),
        );

        $requestCodeOrder = $this->SystemConfigService->get( 'PromioShopConnector.config.RequestCodeOrder' );
        if( empty( $requestCodeOrder ) ) {
            return;
        }


        $client = new Client();
        $url = 'https://confirm.promio-connect.com/pmp.php?';
        $url .= 'a=' . $requestCodeOrder;
        $url .= '&q=' . base64_encode( json_encode( $payload ) );

        #       var_dump($url);
        #        var_dump($payload);
        #        die();
        $res = $client->request( 'GET', $url );
    }

    public function onPageLoaded( PageLoadedEvent $event ): void
    {
        $r = $event->getRequest()->query->get( 'r' );
        $lid = $event->getRequest()->query->get( 'lid' );
        $ln = $event->getRequest()->query->get( 'ln' );

        if( !is_null( $lid ) && !is_null( $r ) ) {
            $data = base64_encode( json_encode( array( 'r' => $r, 'lid' => $lid, 'ln' => $ln ) ) );
            setCookie( 'pnData', $data, time() + ( 60 * 60 * 24 * 30 ) );
        }

    }
}
