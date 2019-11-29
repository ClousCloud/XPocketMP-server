<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link https://pmmp.io/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

class ChunkCacheBlob{
	/** @var int */
	private $hash;
	/** @var string */
	private $payload;

	/**
	 * ChunkCacheBlob constructor.
	 *
	 * @param int    $hash
	 * @param string $payload
	 */
	public function __construct(int $hash, string $payload){
		$this->hash = $hash;
		$this->payload = $payload;
	}

	/**
	 * @return int
	 */
	public function getHash() : int{
		return $this->hash;
	}

	/**
	 * @return string
	 */
	public function getPayload() : string{
		return $this->payload;
	}
}
