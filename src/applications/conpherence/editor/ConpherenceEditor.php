<?php

final class ConpherenceEditor extends PhabricatorApplicationTransactionEditor {

  const ERROR_EMPTY_PARTICIPANTS = 'error-empty-participants';
  const ERROR_EMPTY_MESSAGE = 'error-empty-message';

  public function getEditorApplicationClass() {
    return 'PhabricatorConpherenceApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Conpherence Rooms');
  }

  public static function createThread(
    PhabricatorUser $creator,
    array $participant_phids,
    $title,
    $message,
    PhabricatorContentSource $source,
    $topic) {

    $conpherence = ConpherenceThread::initializeNewRoom($creator);
    $errors = array();
    if (empty($participant_phids)) {
      $errors[] = self::ERROR_EMPTY_PARTICIPANTS;
    } else {
      $participant_phids[] = $creator->getPHID();
      $participant_phids = array_unique($participant_phids);
    }

    if (empty($message)) {
      $errors[] = self::ERROR_EMPTY_MESSAGE;
    }

    if (!$errors) {
      $xactions = array();
      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(ConpherenceTransaction::TYPE_PARTICIPANTS)
        ->setNewValue(array('+' => $participant_phids));
      if ($title) {
        $xactions[] = id(new ConpherenceTransaction())
          ->setTransactionType(
            ConpherenceThreadTitleTransaction::TRANSACTIONTYPE)
          ->setNewValue($title);
      }
      if (strlen($topic)) {
        $xactions[] = id(new ConpherenceTransaction())
          ->setTransactionType(
            ConpherenceThreadTopicTransaction::TRANSACTIONTYPE)
          ->setNewValue($topic);
      }

      $xactions[] = id(new ConpherenceTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
        ->attachComment(
          id(new ConpherenceTransactionComment())
          ->setContent($message)
          ->setConpherencePHID($conpherence->getPHID()));

      id(new ConpherenceEditor())
        ->setActor($creator)
        ->setContentSource($source)
        ->setContinueOnNoEffect(true)
        ->applyTransactions($conpherence, $xactions);
    }

    return array($errors, $conpherence);
  }

  public function generateTransactionsFromText(
    PhabricatorUser $viewer,
    ConpherenceThread $conpherence,
    $text) {

    $xactions = array();
    $xactions[] = id(new ConpherenceTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
      ->attachComment(
        id(new ConpherenceTransactionComment())
        ->setContent($text)
        ->setConpherencePHID($conpherence->getPHID()));
    return $xactions;
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = ConpherenceTransaction::TYPE_PARTICIPANTS;

    $types[] = PhabricatorTransactions::TYPE_COMMENT;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;

    return $types;
  }

  public function getCreateObjectTitle($author, $object) {
    return pht('%s created this room.', $author);
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        if ($this->getIsNewObject()) {
          return array();
        }
        return $object->getParticipantPHIDs();
    }
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        return $this->getPHIDTransactionNewValue($xaction);
    }
  }

  /**
   * We really only need a read lock if we have a comment. In that case, we
   * must update the messagesCount field on the conpherence and
   * seenMessagesCount(s) for the participant(s).
   */
  protected function shouldReadLock(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    $lock = false;
    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_COMMENT:
        $lock =  true;
        break;
    }

    return $lock;
  }

  /**
   * We need to apply initial effects IFF the conpherence is new. We must
   * save the conpherence first thing to make sure we have an id and a phid, as
   * well as create the initial set of participants so that we pass policy
   * checks.
   */
  protected function shouldApplyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    return $this->getIsNewObject();
  }

  protected function applyInitialEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $object->save();

    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case ConpherenceTransaction::TYPE_PARTICIPANTS:
          // Since this is a new ConpherenceThread, we have to create the
          // participation data asap to pass policy checks. For existing
          // ConpherenceThreads, the existing participation is correct
          // at this stage. Note that later in applyCustomExternalTransaction
          // this participation data will be updated, particularly the
          // behindTransactionPHID which is just a generated dummy for now.
          $participants = array();
          $phids = $this->getPHIDTransactionNewValue($xaction, array());
          foreach ($phids as $phid) {
            if ($phid == $this->getActor()->getPHID()) {
              $status = ConpherenceParticipationStatus::UP_TO_DATE;
              $message_count = 1;
            } else {
              $status = ConpherenceParticipationStatus::BEHIND;
              $message_count = 0;
            }
            $participants[$phid] =
              id(new ConpherenceParticipant())
              ->setConpherencePHID($object->getPHID())
              ->setParticipantPHID($phid)
              ->setParticipationStatus($status)
              ->setDateTouched(time())
              ->setBehindTransactionPHID($xaction->generatePHID())
              ->setSeenMessageCount($message_count)
              ->save();
            $object->attachParticipants($participants);
          }
          break;
      }
    }
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        if (!$this->getIsNewObject()) {}
        break;
    }

  }

  protected function applyBuiltinInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_COMMENT:
        $object->setMessageCount((int)$object->getMessageCount() + 1);
        break;
    }

    return parent::applyBuiltinInternalTransaction($object, $xaction);
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        if ($this->getIsNewObject()) {
          continue;
        }
        $participants = $object->getParticipants();

        $old_map = array_fuse($xaction->getOldValue());
        $new_map = array_fuse($xaction->getNewValue());

        $remove = array_keys(array_diff_key($old_map, $new_map));
        foreach ($remove as $phid) {
          $remove_participant = $participants[$phid];
          $remove_participant->delete();
          unset($participants[$phid]);
        }

        $add = array_keys(array_diff_key($new_map, $old_map));
        foreach ($add as $phid) {
          if ($phid == $this->getActor()->getPHID()) {
            $status = ConpherenceParticipationStatus::UP_TO_DATE;
            $message_count = $object->getMessageCount();
          } else {
            $status = ConpherenceParticipationStatus::BEHIND;
            $message_count = 0;
          }
          $participants[$phid] =
            id(new ConpherenceParticipant())
            ->setConpherencePHID($object->getPHID())
            ->setParticipantPHID($phid)
            ->setParticipationStatus($status)
            ->setDateTouched(time())
            ->setBehindTransactionPHID($xaction->getPHID())
            ->setSeenMessageCount($message_count)
            ->save();
        }
        $object->attachParticipants($participants);
        break;
    }
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    if (!$xactions) {
      return $xactions;
    }

    $message_count = 0;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_COMMENT:
          $message_count++;

          // update everyone's participation status on a message -only-
          $xaction_phid = $xaction->getPHID();
          $behind = ConpherenceParticipationStatus::BEHIND;
          $up_to_date = ConpherenceParticipationStatus::UP_TO_DATE;
          $participants = $object->getParticipants();
          $user = $this->getActor();
          $time = time();
          foreach ($participants as $phid => $participant) {
            if ($phid != $user->getPHID()) {
              if ($participant->getParticipationStatus() != $behind) {
                $participant->setBehindTransactionPHID($xaction_phid);
                $participant->setSeenMessageCount(
                  $object->getMessageCount() - $message_count);
              }
              $participant->setParticipationStatus($behind);
              $participant->setDateTouched($time);
            } else {
              $participant->setSeenMessageCount($object->getMessageCount());
              $participant->setBehindTransactionPHID($xaction_phid);
              $participant->setParticipationStatus($up_to_date);
              $participant->setDateTouched($time);
            }
            $participant->save();
          }

          PhabricatorUserCache::clearCaches(
            PhabricatorUserMessageCountCacheType::KEY_COUNT,
            array_keys($participants));

          break;
      }
    }

    if ($xactions) {
      $data = array(
        'type'        => 'message',
        'threadPHID'  => $object->getPHID(),
        'messageID'   => last($xactions)->getID(),
        'subscribers' => array($object->getPHID()),
      );

      PhabricatorNotificationClient::tryToPostMessage($data);
    }

    return $xactions;
  }

  protected function requireCapabilities(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    parent::requireCapabilities($object, $xaction);

    switch ($xaction->getTransactionType()) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        $old_map = array_fuse($xaction->getOldValue());
        $new_map = array_fuse($xaction->getNewValue());

        $add = array_keys(array_diff_key($new_map, $old_map));
        $rem = array_keys(array_diff_key($old_map, $new_map));

        $actor_phid = $this->getActingAsPHID();

        $is_join = (($add === array($actor_phid)) && !$rem);
        $is_leave = (($rem === array($actor_phid)) && !$add);

        if ($is_join) {
          // Anyone can join a thread they can see.
        } else if ($is_leave) {
          // Anyone can leave a thread.
        } else {
          // You need CAN_EDIT to add or remove participants. For additional
          // discussion, see D17696 and T4411.
          PhabricatorPolicyFilter::requireCapability(
            $this->requireActor(),
            $object,
            PhabricatorPolicyCapability::CAN_EDIT);
        }

        break;
      case ConpherenceThreadTitleTransaction::TRANSACTIONTYPE:
      case ConpherenceThreadTopicTransaction::TRANSACTIONTYPE:
        PhabricatorPolicyFilter::requireCapability(
          $this->requireActor(),
          $object,
          PhabricatorPolicyCapability::CAN_EDIT);
        break;
    }
  }

  protected function mergeTransactions(
    PhabricatorApplicationTransaction $u,
    PhabricatorApplicationTransaction $v) {

    $type = $u->getTransactionType();
    switch ($type) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        return $this->mergePHIDOrEdgeTransactions($u, $v);
    }

    return parent::mergeTransactions($u, $v);
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ConpherenceReplyHandler())
      ->setActor($this->getActor())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $title = $object->getTitle();
    if (!$title) {
      $title = pht(
        '%s sent you a message.',
        $this->getActor()->getUserName());
    }
    $phid = $object->getPHID();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("Z{$id}: {$title}")
      ->addHeader('Thread-Topic', "Z{$id}: {$phid}");
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    $to_phids = array();

    $participants = $object->getParticipants();
    if (!$participants) {
      return $to_phids;
    }

    $participant_phids = mpull($participants, 'getParticipantPHID');

    $users = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs($participant_phids)
      ->needUserSettings(true)
      ->execute();
    $users = mpull($users, null, 'getPHID');

    $notification_key = PhabricatorConpherenceNotificationsSetting::SETTINGKEY;
    $notification_email =
      PhabricatorConpherenceNotificationsSetting::VALUE_CONPHERENCE_EMAIL;

    foreach ($participants as $phid => $participant) {
      $user = idx($users, $phid);
      if ($user) {
        $default = $user->getUserSetting($notification_key);
      } else {
        $default = $notification_email;
      }

      $settings = $participant->getSettings();
      $notifications = idx($settings, 'notifications', $default);

      if ($notifications == $notification_email) {
        $to_phids[] = $phid;
      }
    }

    return $to_phids;
  }

  protected function getMailCC(PhabricatorLiskDAO $object) {
    return array();
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);
    $body->addLinkSection(
      pht('CONPHERENCE DETAIL'),
      PhabricatorEnv::getProductionURI('/'.$object->getMonogram()));

    return $body;
  }

  protected function addEmailPreferenceSectionToMailBody(
    PhabricatorMetaMTAMailBody $body,
    PhabricatorLiskDAO $object,
    array $xactions) {

    $href = PhabricatorEnv::getProductionURI(
      '/'.$object->getMonogram().'?settings');
    $label = pht('EMAIL PREFERENCES FOR THIS ROOM');
    $body->addLinkSection($label, $href);
  }

  protected function getMailSubjectPrefix() {
    return PhabricatorEnv::getEnvConfig('metamta.conpherence.subject-prefix');
  }

  protected function supportsSearch() {
    return true;
  }

  protected function validateTransaction(
    PhabricatorLiskDAO $object,
    $type,
    array $xactions) {

    $errors = parent::validateTransaction($object, $type, $xactions);

    switch ($type) {
      case ConpherenceTransaction::TYPE_PARTICIPANTS:
        foreach ($xactions as $xaction) {
          $new_phids = $this->getPHIDTransactionNewValue($xaction, array());
          $old_phids = nonempty($object->getParticipantPHIDs(), array());
          $phids = array_diff($new_phids, $old_phids);

          if (!$phids) {
            continue;
          }

          $users = id(new PhabricatorPeopleQuery())
            ->setViewer($this->requireActor())
            ->withPHIDs($phids)
            ->execute();
          $users = mpull($users, null, 'getPHID');
          foreach ($phids as $phid) {
            if (isset($users[$phid])) {
              continue;
            }
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht('Invalid'),
              pht('New room participant "%s" is not a valid user.', $phid),
              $xaction);
          }
        }
        break;
    }

    return $errors;
  }
}
