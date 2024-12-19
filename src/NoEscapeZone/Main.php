
<?php

declare(strict_types=1);

namespace NoEscapeZone;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

class Main extends PluginBase implements Listener {

    private const COOLDOWN_TIME = 15;
    private const MESSAGE_COOLDOWN = 2;

    private array $taggedPlayers = [];
    private array $lastMessageTime = [];
    private array $worldAreas = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->loadAreasFromConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(TextFormat::GREEN . "NoEscapeZone plugin enabled!");
    }

    private function loadAreasFromConfig(): void {
        $areas = $this->getConfig()->get("areas", []);
        foreach ($areas as $worldName => $areaData) {
            $this->worldAreas[$worldName] = [
                'min' => [
                    $areaData['min_x'],
                    $areaData['min_y'],
                    $areaData['min_z']
                ],
                'max' => [
                    $areaData['max_x'],
                    $areaData['max_y'],
                    $areaData['max_z']
                ]
            ];
        }
    }

    public function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . "NoEscapeZone plugin disabled!");
    }

    private function isInArea(Vector3 $pos, string $worldName): bool {
        if (!isset($this->worldAreas[$worldName])) {
            return false;
        }

        $area = $this->worldAreas[$worldName];
        return $pos->getX() >= $area['min'][0] && $pos->getX() <= $area['max'][0]
            && $pos->getY() >= $area['min'][1] && $pos->getY() <= $area['max'][1]
            && $pos->getZ() >= $area['min'][2] && $pos->getZ() <= $area['max'][2];
    }

    private function canSendMessage(Player $player, string $messageType): bool {
        $playerId = $player->getName();
        $currentTime = time();
        
        if (!isset($this->lastMessageTime[$playerId])) {
            $this->lastMessageTime[$playerId] = [];
        }

        if (!isset($this->lastMessageTime[$playerId][$messageType]) || 
            $currentTime - $this->lastMessageTime[$playerId][$messageType] >= self::MESSAGE_COOLDOWN) {
            $this->lastMessageTime[$playerId][$messageType] = $currentTime;
            return true;
        }
        
        return false;
    }

    private function tagPlayer(Player $player): void {
        $currentTime = time();
        $playerName = $player->getName();
        
        $isNewCombat = !isset($this->taggedPlayers[$playerName]) || 
                       ($currentTime - $this->taggedPlayers[$playerName] >= self::COOLDOWN_TIME);
        
        $this->taggedPlayers[$playerName] = $currentTime;
        
        if ($isNewCombat && $this->canSendMessage($player, 'combat')) {
            $player->sendMessage(TextFormat::RED . "You are in combat! You cannot leave this area for " . self::COOLDOWN_TIME . " seconds.");
        }
    }

    public function handleCommandEvent(CommandEvent $event): void {
        $sender = $event->getSender();
        
        if ($sender instanceof Player) {
            $worldName = $sender->getWorld()->getFolderName();
            if (isset($this->worldAreas[$worldName]) && $this->isInArea($sender->getPosition()->asVector3(), $worldName)) {
                $event->cancel();
                if ($this->canSendMessage($sender, 'command')) {
                    $sender->sendMessage(TextFormat::RED . "You cannot use commands in this area!");
                }
            }
        }
    }

    public function onPlayerDamage(EntityDamageByEntityEvent $event): void {
        $entity = $event->getEntity();
        $damager = $event->getDamager();

        if ($entity instanceof Player && $damager instanceof Player) {
            $worldName = $entity->getWorld()->getFolderName();
            if (isset($this->worldAreas[$worldName])) {
                $entityInArea = $this->isInArea($entity->getPosition()->asVector3(), $worldName);
                $damagerInArea = $this->isInArea($damager->getPosition()->asVector3(), $worldName);

                if ($entityInArea || $damagerInArea) {
                    if ($entityInArea) {
                        $this->tagPlayer($entity);
                    }
                    if ($damagerInArea) {
                        $this->tagPlayer($damager);
                    }
                }
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();
        $playerName = $player->getName();
        $worldName = $player->getWorld()->getFolderName();

        if (isset($this->worldAreas[$worldName])) {
            $wasInArea = $this->isInArea($from->asVector3(), $worldName);
            $isInArea = $this->isInArea($to->asVector3(), $worldName);
            $currentTime = time();
            
            if (isset($this->taggedPlayers[$playerName])) {
                $timeLeft = self::COOLDOWN_TIME - ($currentTime - $this->taggedPlayers[$playerName]);
                
                if ($timeLeft > 0) {
                    if ($wasInArea && !$isInArea) {
                        $event->cancel();
                        if ($this->canSendMessage($player, 'leave')) {
                            $player->sendMessage(TextFormat::RED . "You cannot leave the area while in combat! Time remaining: {$timeLeft}s");
                        }
                    }
                } else {
                    unset($this->taggedPlayers[$playerName]);
                    if ($wasInArea && $this->canSendMessage($player, 'combat_expired')) {
                        $player->sendMessage(TextFormat::GREEN . "You are no longer in combat. You can leave the area.");
                    }
                }
            }
        }
    }

    private function cleanupMessageCooldowns(): void {
        foreach ($this->lastMessageTime as $playerId => $messageTypes) {
            if ($this->getServer()->getPlayerExact($playerId) === null) {
                unset($this->lastMessageTime[$playerId]);
            }
        }
    }
}
