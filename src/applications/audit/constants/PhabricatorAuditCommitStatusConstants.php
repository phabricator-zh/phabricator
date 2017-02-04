<?php

final class PhabricatorAuditCommitStatusConstants extends Phobject {

  const NONE                = 0;
  const NEEDS_AUDIT         = 1;
  const CONCERN_RAISED      = 2;
  const PARTIALLY_AUDITED   = 3;
  const FULLY_AUDITED       = 4;
  const NEEDS_VERIFICATION = 5;

  public static function getStatusNameMap() {
    $map = array(
      self::NONE                => pht('No Audits'),
      self::NEEDS_AUDIT         => pht('Audit Required'),
      self::CONCERN_RAISED      => pht('Concern Raised'),
      self::NEEDS_VERIFICATION => pht('Needs Verification'),
      self::PARTIALLY_AUDITED   => pht('Partially Audited'),
      self::FULLY_AUDITED       => pht('Audited'),
    );

    return $map;
  }

  public static function getStatusName($code) {
    return idx(self::getStatusNameMap(), $code, pht('Unknown'));
  }

  public static function getOpenStatusConstants() {
    return array(
      self::CONCERN_RAISED,
      self::NEEDS_AUDIT,
      self::NEEDS_VERIFICATION,
      self::PARTIALLY_AUDITED,
    );
  }

  public static function getStatusColor($code) {
    switch ($code) {
      case self::CONCERN_RAISED:
        $color = 'red';
        break;
      case self::NEEDS_AUDIT:
        $color = 'orange';
        break;
      case self::PARTIALLY_AUDITED:
        $color = 'yellow';
        break;
      case self::FULLY_AUDITED:
        $color = 'green';
        break;
      case self::NONE:
        $color = 'bluegrey';
        break;
      case self::NEEDS_VERIFICATION:
        $color = 'indigo';
        break;
      default:
        $color = null;
        break;
    }
    return $color;
  }

  public static function getStatusIcon($code) {
    switch ($code) {
      case self::CONCERN_RAISED:
        $icon = 'fa-times-circle';
        break;
      case self::NEEDS_AUDIT:
        $icon = 'fa-exclamation-circle';
        break;
      case self::PARTIALLY_AUDITED:
        $icon = 'fa-check-circle-o';
        break;
      case self::FULLY_AUDITED:
        $icon = 'fa-check-circle';
        break;
      case self::NONE:
        $icon = 'fa-check';
        break;
      case self::NEEDS_VERIFICATION:
        $icon = 'fa-refresh';
        break;
      default:
        $icon = null;
        break;
    }
    return $icon;
  }

}
