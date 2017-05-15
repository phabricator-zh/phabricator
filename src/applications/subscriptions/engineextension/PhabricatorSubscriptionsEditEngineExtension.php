<?php

final class PhabricatorSubscriptionsEditEngineExtension
  extends PhabricatorEditEngineExtension {

  const EXTENSIONKEY = 'subscriptions.subscribers';
  const FIELDKEY = 'subscriberPHIDs';

  const EDITKEY_ADD = 'subscribers.add';
  const EDITKEY_SET = 'subscribers.set';
  const EDITKEY_REMOVE = 'subscribers.remove';

  public function getExtensionPriority() {
    return 750;
  }

  public function isExtensionEnabled() {
    return true;
  }

  public function getExtensionName() {
    return pht('Subscriptions');
  }

  public function supportsObject(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {
    return ($object instanceof PhabricatorSubscribableInterface);
  }

  public function buildCustomEditFields(
    PhabricatorEditEngine $engine,
    PhabricatorApplicationTransactionInterface $object) {

    $subscribers_type = PhabricatorTransactions::TYPE_SUBSCRIBERS;

    $object_phid = $object->getPHID();
    if ($object_phid) {
      $sub_phids = PhabricatorSubscribersQuery::loadSubscribersForPHID(
        $object_phid);
    } else {
      $sub_phids = array();
    }

    $subscribers_field = id(new PhabricatorSubscribersEditField())
      ->setKey(self::FIELDKEY)
      ->setLabel(pht('Subscribers'))
      ->setEditTypeKey('subscribers')
      ->setAliases(array('subscriber', 'subscribers'))
      ->setIsCopyable(true)
      ->setUseEdgeTransactions(true)
      ->setCommentActionLabel(pht('Change Subscribers'))
      ->setCommentActionOrder(9000)
      ->setDescription(pht('Choose subscribers.'))
      ->setTransactionType($subscribers_type)
      ->setValue($sub_phids);

    $subscribers_field->setViewer($engine->getViewer());

    $edit_add = $subscribers_field->getConduitEditType(self::EDITKEY_ADD)
      ->setConduitDescription(pht('Add subscribers.'));

    $edit_set = $subscribers_field->getConduitEditType(self::EDITKEY_SET)
      ->setConduitDescription(
        pht('Set subscribers, overwriting current value.'));

    $edit_rem = $subscribers_field->getConduitEditType(self::EDITKEY_REMOVE)
      ->setConduitDescription(pht('Remove subscribers.'));

    return array(
      $subscribers_field,
    );
  }

}
