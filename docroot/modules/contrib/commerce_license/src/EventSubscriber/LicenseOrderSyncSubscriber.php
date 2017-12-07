<?php

namespace Drupal\commerce_license\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Changes a license's state in sync with an order's workflow.
 */
class LicenseOrderSyncSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The license storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $licenseStorage;

  /**
   * Constructs a new LicenseOrderSyncSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->licenseStorage = $entity_type_manager->getStorage('commerce_license');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      // Events defined by state_machine, derived from the workflow defined in
      // commerce_order.workflows.yml.
      // See Drupal\state_machine\Plugin\Field\FieldType\StateItem::dispatchTransitionEvent()

      // TODO: Revisit these transitions and check they are correct.

      // Events for reaching the 'fulfillment' state. This depends on whether
      // the workflow in use has validation or not.
      'commerce_order.place.post_transition' => ['onCartOrderFulfillment', -100],
      'commerce_order.validate.post_transition' => ['onCartOrderFulfillment', -100],
      // Event for reaching the 'canceled' state.
      'commerce_order.cancel.post_transition' => ['onCartOrderCancel', -100],
    ];
    return $events;
  }

  /**
   * Reacts to an order reaching fulfillment state.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   */
  public function onCartOrderFulfillment(WorkflowTransitionEvent $event) {
    // Only act if we are reaching the 'fulfillment' state. Different workflows
    // use the same transition names to reach different states.
    if ($event->getToState()->getId() != 'fulfillment') {
      return;
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    $license_order_items = $this->getOrderItemsWithLicensedProducts($order);

    foreach ($license_order_items as $order_item) {
      // Only create a new license if the order item doesn't already have one:
      // this allows for orders to be created programmatically with a configured
      // license.
      if (empty($order_item->license->entity)) {
        // Create a new license.
        $license = $this->licenseStorage->createFromOrderItem($order_item);

        $license->save();

        // Set the license field on the order item so we have a reference
        // and can get hold of it in later events.
        $order_item->license = $license->id();
        $order_item->save();
      }
      else {
        // Get the existing license the order item refers to.
        $license = $order_item->license->entity;
      }

      // Attempt to activate and confirm the license.
      // TODO: This needs to be expanded for synchronizable licenses.
      // TODO: how does a license type plugin indicate that it's not able to
      // activate? And how do we notify the order at this point?
      $transition = $license->getState()->getWorkflow()->getTransition('activate');
      $license->getState()->applyTransition($transition);
      $license->save();

      $transition = $license->getState()->getWorkflow()->getTransition('confirm');
      $license->getState()->applyTransition($transition);
      $license->save();
    }
  }

  /**
   * Reacts to an order being cancelled.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   */
  public function onCartOrderCancel(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    $license_order_items = $this->getOrderItemsWithLicensedProducts($order);

    foreach ($license_order_items as $order_item) {
      // Get the license from the order item.
      $license = $order_item->license->entity;

      // Cancel the license.
      $transition = $license->getState()->getWorkflow()->getTransition('cancel');
      $license->getState()->applyTransition($transition);
      $license->save();
    }
  }

  /**
   * Returns the order items from an order which are for licensed products.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   An array of the order items whose purchased products are for licenses.
   */
  protected function getOrderItemsWithLicensedProducts(OrderInterface $order) {
    $return_items = [];

    foreach ($order->getItems() as $order_item) {
      // Skip order items that do not have a license reference field.
      // We check order items rather than the purchased entity to allow products
      // with licenses to be purchased without the checkout flow triggering
      // our synchronization. This is for cases such as recurring orders, where
      // the license entity should not be put through the normal workflow.
      // Checking the order item's bundle for our entity trait is expensive, as
      // it requires loading the bundle entity to call hasTrait() on it.
      // For now, just check whether the order item has our trait's field on it.
      // @see https://www.drupal.org/node/2894805
      if (!$order_item->hasField('license')) {
        continue;
      }

      $return_items[] = $order_item;
    }

    return $return_items;
  }

}