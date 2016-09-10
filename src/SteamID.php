<?php

namespace SteamID;

use Exception;
use Math_BigInteger;


/**
 * Class SteamID
 * A modified version of Dr McKay's SteamID class to work with BigInteger from phpseclib.
 *
 * It will use 64-bit integers if possible, if not it'll use either gmp or bcmath from phpseclib.
 */
class SteamID {
	/**
	 * @var int The four parts of a SteamID
	 */
	public $universe;
	public $type;
	public $instance;
	public $accountid;

	/**
	 * SteamID universes
	 */
	const UNIVERSE_INVALID = 0;
	const UNIVERSE_PUBLIC = 1;
	const UNIVERSE_BETA = 2;
	const UNIVERSE_INTERNAL = 3;
	const UNIVERSE_DEV = 4;

	/**
	 * SteamID account types
	 */
	const TYPE_INVALID = 0;
	const TYPE_INDIVIDUAL = 1;
	const TYPE_MULTISEAT = 2;
	const TYPE_GAMESERVER = 3;
	const TYPE_ANON_GAMESERVER = 4;
	const TYPE_PENDING = 5;
	const TYPE_CONTENT_SERVER = 6;
	const TYPE_CLAN = 7;
	const TYPE_CHAT = 8;
	const TYPE_P2P_SUPER_SEEDER = 9;
	const TYPE_ANON_USER = 10;

	/**
	 * SteamID account instances
	 */
	const INSTANCE_ALL = 0;
	const INSTANCE_DESKTOP = 1;
	const INSTANCE_CONSOLE = 2;
	const INSTANCE_WEB = 4;

	/**
	 * Instance mask is used in chat instance flag calculations, but older versions of PHP don't accept
	 * expressions in const vars so we can't use them here.
	 */
	const ACCOUNTID_MASK = 0xFFFFFFFF;
	const ACCOUNT_INSTANCE_MASK = 0x000FFFFF;

	/**
	 * Chat instance flags (check if your instanceid has one of these flags to see if it's that kind of chat)
	 */
	const CHAT_INSTANCE_FLAG_CLAN = 0x80000;
	const CHAT_INSTANCE_FLAG_LOBBY = 0x40000;
	const CHAT_INSTANCE_FLAG_MMSLOBBY = 0x20000;

	/**
	 * @var array
	 */
	public static $typeChars = [
		self::TYPE_INVALID => 'I',
		self::TYPE_INDIVIDUAL => 'U',
		self::TYPE_MULTISEAT => 'M',
		self::TYPE_GAMESERVER => 'G',
		self::TYPE_ANON_GAMESERVER => 'A',
		self::TYPE_PENDING => 'P',
		self::TYPE_CONTENT_SERVER => 'C',
		self::TYPE_CLAN => 'g',
		self::TYPE_CHAT => 'T',
		self::TYPE_ANON_USER => 'a'
	];

	/**
	 * @param null|string|int $id
	 * @throws Exception If the input format was not recognized as a SteamID
	 */
	public function __construct($id = null) {
		if (!$id) {
			$this->universe = self::UNIVERSE_INVALID;
			$this->type = self::TYPE_INVALID;
			$this->instance = self::INSTANCE_ALL;
			$this->accountid = 0;
			return;
		}

		if (preg_match('/^STEAM_([0-5]):([0-1]):([0-9]+)$/', $id, $matches)) {
			// Steam2 ID
			if ($matches[1] <= self::UNIVERSE_PUBLIC) {
				$this->universe = self::UNIVERSE_PUBLIC;
			} else {
				$this->universe = (int) $matches[1];
			}

			$this->type = self::TYPE_INDIVIDUAL;
			$this->instance = self::INSTANCE_DESKTOP;
			$this->accountid = (int) ($matches[3] * 2) + $matches[2];
		} elseif (preg_match('/^\\[([a-zA-Z]):([0-5]):([0-9]+)(:[0-9]+)?\\]$/', $id, $matches)) {
			// Steam3 ID
			$this->universe = (int) $matches[2];
			$this->accountid = (int) $matches[3];

			$type_char = $matches[1];

			if (!empty($matches[4])) {
				$this->instance = (int) substr($matches[4], 1);
			} else {
				switch ($type_char) {
				case 'g':
				case 'T':
				case 'c':
				case 'L':
					$this->instance = self::INSTANCE_ALL;
					break;

				default:
					$this->instance = self::INSTANCE_DESKTOP;
				}
			}

			if ($type_char == 'c') {
				$this->instance |= self::CHAT_INSTANCE_FLAG_CLAN;
				$this->type = self::TYPE_CHAT;
			} elseif ($type_char == 'L') {
				$this->instance |= self::CHAT_INSTANCE_FLAG_LOBBY;
				$this->type = self::TYPE_CHAT;
			} else {
				$this->type = self::getTypeFromChar($type_char);
			}
		} elseif (!is_numeric($id)) {
			throw new Exception("Unknown ID format");
		} else {
			// SteamID64
			if (PHP_INT_SIZE == 4) {
				// Wrapper for BigInteger
				$bigint = new Math_BigInteger($id);
				$this->universe = (int) $bigint->bitwise_rightShift(56)->toString();
				$this->type = ((int) $bigint->bitwise_rightShift(52)->toString()) & 0xF;
				$this->instance = ((int) $bigint->bitwise_rightShift(32)->toString()) & 0xFFFFF;
				$this->accountid = (int) $bigint->bitwise_and(new Math_BigInteger('0xFFFFFFFF', 16))->toString();
			} else {
				$this->universe = $id >> 56;
				$this->type = ($id >> 52) & 0xF;
				$this->instance = ($id >> 32) & 0xFFFFF;
				$this->accountid = $id & 0xFFFFFFFF;
			}
		}
	}

	/**
	 * @return bool True if the SteamID is considered "valid", false otherwise
	 */
	public function isValid() {
		if ($this->type <= self::TYPE_INVALID || $this->type > self::TYPE_ANON_USER) {
			return false;
		}

		if ($this->universe <= self::UNIVERSE_INVALID || $this->universe > self::UNIVERSE_DEV) {
			return false;
		}

		if ($this->type == self::TYPE_INDIVIDUAL && ($this->accountid === 0 || $this->instance > self::INSTANCE_WEB)) {
			return false;
		}

		if ($this->type == self::TYPE_CLAN && ($this->accountid === 0 || $this->instance != self::INSTANCE_ALL)) {
			return false;
		}

		if ($this->type == self::TYPE_GAMESERVER && $this->accountid === 0) {
			return false;
		}

		return true;
	}

	/**
	 * Gets the rendered STEAM_X:Y:Z format.
	 * @param bool $newerFormat If the universe is public, should X be 1 instead of 0?
	 * @return string
	 * @throws Exception If this isn't an individual account SteamID
	 */
	public function getSteam2RenderedID($newerFormat = false) {
		if ($this->type != self::TYPE_INDIVIDUAL) {
			throw new Exception("Can't get Steam2 rendered ID for non-individual ID");
		} else {
			$universe = $this->universe;
			if ($universe == 1 && !$newerFormat) {
				$universe = 0;
			}

			return 'STEAM_' . $universe . ':' . ($this->accountid & 1) . ':' . floor($this->accountid / 2);
		}
	}

	/**
	 * Gets the rendered [T:U:A(:I)] format (T = type, U = universe, A = accountid, I = instance)
	 * @return string
	 */
	public function getSteam3RenderedID() {
		$type_char = self::$typeChars[$this->type] ? self::$typeChars[$this->type] : 'i';

		if ($this->instance & self::CHAT_INSTANCE_FLAG_CLAN) {
			$type_char = 'c';
		} elseif ($this->instance & self::CHAT_INSTANCE_FLAG_LOBBY) {
			$type_char = 'L';
		}

		$render_instance = ($this->type == self::TYPE_ANON_GAMESERVER || $this->type == self::TYPE_MULTISEAT ||
			($this->type == self::TYPE_INDIVIDUAL && $this->instance != self::INSTANCE_DESKTOP));

		return '[' . $type_char . ':' . $this->universe . ':' . $this->accountid . ($render_instance ? ':' . $this->instance : '') . ']';
	}

	/**
	 * Gets the SteamID as a 64-bit integer
	 * @return string
	 */
	public function getSteamID64() {
		if (PHP_INT_SIZE == 4) {
			$ret = new Math_BigInteger();
			$ret = $ret->add((new Math_BigInteger($this->universe))->bitwise_leftShift(56));
			$ret = $ret->add((new Math_BigInteger($this->type))->bitwise_leftShift(52));
			$ret = $ret->add((new Math_BigInteger($this->instance))->bitwise_leftShift(32));
			$ret = $ret->add(new Math_BigInteger($this->accountid));
			return $ret->toString();
		}
		return (string) (($this->universe << 56) | ($this->type << 52) | ($this->instance << 32) | ($this->accountid));
	}

	/**
	 * Gets the SteamID as a 64-bit integer in a string
	 * @return string
	 */
	public function __toString() {
		return $this->getSteamID64();
	}

	/**
	 * Returns whether or not this SteamID is for a clan (Steam group) chat
	 * @return bool
	 */
	public function isClanChat() {
		return $this->type == self::TYPE_CHAT && ($this->instance & self::CHAT_INSTANCE_FLAG_CLAN);
	}

	/**
	 * Returns whether or not this SteamID is for a lobby
	 * @return bool
	 */
	public function isLobbyChat() {
		return $this->type == self::TYPE_CHAT && ($this->instance & self::CHAT_INSTANCE_FLAG_LOBBY);
	}

	/**
	 * Returns whether or not this SteamID is for a matchmaking lobby
	 * @return bool
	 */
	public function isMMSLobbyChat() {
		return $this->type == self::TYPE_CHAT && ($this->instance & self::CHAT_INSTANCE_FLAG_MMSLOBBY);
	}

	private static function getTypeFromChar($char) {
		foreach (self::$typeChars as $type => $typechar) {
			if ($typechar == $char) {
				return $type;
			}
		}

		return self::TYPE_INVALID;
	}
}
