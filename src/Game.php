<?php

namespace App;

use App\Audio\SoundManager;
use App\Entities\GameObjectManager;
use App\Entities\Physics\CollisionSystem;
use App\Entities\Player;
use App\Gfx\CrtFilter;
use App\Gfx\Gfx;
use App\Gfx\TextRenderer;
use App\Input\Pad;
use App\Level\LevelManager;
use App\Multiplayer\BotBrain;
use App\Multiplayer\BotRoute;
use App\Multiplayer\Versus;
use App\Save\BestTimes;
use App\Save\GhostRecorder;
use App\Save\Options;
use App\Stage\Screens;
use App\Stage\Stage;
use App\Util\Lang;
use Evenement\EventEmitter;
use JumPHP\Kernel\Engine;
use JumPHP\Kernel\Platform;

/**
 * The whole game, as the original Game class ran it, now driven by JumPHP (App\Scene\GameScene):
 * frame() once per engine frame (input, then one game tick), render() draws the game view (JumPHP's view
 * texture, sized by updateView() like the original), renderWindow() what is drawn at window resolution
 * (title / menus / tallies, multiplayer scores, CRT filter, debug panels: a JumPHP renderer overlay).
 */
class Game extends EventEmitter
{
    private int $debugMode = 0 ;

    private GameObjectManager $objectManager;

    private Screens $screens;

    /** Start button pressed since the last tick (title / map / act complete screens). */
    private bool $buttonPressed = false;
    /** Act that led to a Scrap Brain transporter room (the game resumes after it). */
    private ?int $warpFrom = null;
    /** Debug (keypad 5): Sonic follows the free camera. */
    private bool $carryPlayer = false;

    // View: game pixels -> screen pixels. The automatic zoom keeps a view about 426px high whatever the window /
    // screen size, the keypad +/- step from it, * switches to the 256x192 Master System screen.
    private const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3, 3.5, 4, 5, 6, 8];
    private const DEFAULT_VIEW_HEIGHT = 426;
    private int $zoomOffset = 0;
    /** Height (px) the automatic zoom aims at: DEFAULT_VIEW_HEIGHT, ~192 on a phone (bigger sprites, full width). */
    public int $viewHeight = self::DEFAULT_VIEW_HEIGHT;
    /** Multiplayer match in progress (title menu > MULTIJOUEUR), or null. */
    private ?Versus $versus = null;
    /** Options > FILTRE CRT (created on first use). */
    private ?CrtFilter $crt = null;
    private bool $smsView = false;

    /** F6: panel with Sonic's coordinates over the screen (to report bugs). */
    private bool $coordsPanel = false;
    /** Debug (F7): the computer plays for Sonic, a BotBrain on his joypad, a BotRoute for the way; how long it still holds the jump. */
    private ?BotBrain $autoplay = null;
    private ?BotRoute $autoplayRoute = null;
    /** Autoplay: where Sonic last stood, and for how long he has not (in the air, or inside the ground). */
    private ?array $autoplaySafe = null;
    private float $autoplayAir = 0;
    private float $autoplayJump = 0;
    /** The autoplay was asked by the scene (GameScene bot: the smoke test): it stays on from act to act. */
    private bool $autoplayKept = false;
    private TextRenderer $text;
    /** Title menu options (var/options.json) and the ghost of the fastest run of each level (var/ghosts). */
    private Options $options;
    private GhostRecorder $ghost;
    /** Best times of each act (title menu > best times), var/besttimes.json. */
    private BestTimes $bestTimes;
    /** Time already spent in the act before a Scrap Brain transporter / the Sky Base 2 door (rooms reset the timer). */
    private float $actTimeBefore = 0;
    /** Name typed for the first best time of this session, used for the next ones until the game is closed. */
    private ?string $playerName = null;
    /** Best time waiting for the player's name: [act, index, time], and what to do once it is typed. */
    private ?array $pendingName = null;
    private ?\Closure $afterName = null;
    /** The joypad of this frame (JumPHP actions + the raw keyboard for the debug keys), set by frame(). */
    private Pad $pad;
    /** Seconds since the debug blocks touched by Sonic were last cleaned (every 0.1 s). */
    private float $cleanupClock = 0;

    public function __construct(
        private Engine $engine,
        public Gfx $sdl,
        private Player $player,
        private Stage $scene,
        private LevelManager $levelManager,
        private SoundManager $soundManager,
        private CollisionSystem $collisionSystem
    )
    {
        $scene->setPlayer($this->player);
        $this->objectManager = new GameObjectManager($sdl);
        $scene->setObjectManager($this->objectManager);
        $this->screens = new Screens($sdl);
        $this->text = new TextRenderer($sdl, $engine->config->path('assets'));
        $this->options = new Options($engine->options);
        Lang::set($this->options->getLang() ?: Lang::detect());
        $this->ghost = new GhostRecorder($engine->storage, 'ghosts');
        $this->bestTimes = new BestTimes($engine->storage, 'besttimes.json');
        $this->screens->setMenu($this->text, $this->options, $this->ghost, $this->bestTimes);
        // phones: a view about 200 lines high (bigger sprites), like the original page asked
        if (Platform::isMobile()) $this->viewHeight = 200;
    }

    /** Next special stage ($D23F, from $1C = our level 28) and the act to go on from after it. */
    private int $nextSpecialStage = 28;

    /**
     * Demo mode (_1bad): Green Hill act 1 played with the joypad of _1bc6, one byte every 32 frames
     * (active low: bit 0 up, 1 down, 2 left, 3 right, 4-5 buttons). A button press, Sonic's death or the end
     * of the table go back to the title screen.
     */
    private const DEMO_INPUT = [
        0xF7, 0xF7, 0xF7, 0xF7, 0xDF, 0xF7, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xF7, 0xFF, 0xDF, 0xF7, 0xF7, 0xDF, 0xF7, 0xF7, 0xF7,
        0xF7, 0xFF, 0xFF, 0xDF, 0xF7, 0xFF, 0xFF, 0xFF, 0xFB, 0xF7, 0xF7, 0xF5, 0xFF, 0xFF, 0xFF, 0xFF, 0xFB, 0xFB, 0xF9, 0xFF,
        0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xFB,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xFF, 0xD7,
        0xFB, 0xF7, 0xF7, 0xF7, 0xF7, 0xFB, 0xFB, 0xF7, 0xFF, 0xD7, 0xFB, 0xFF, 0xF7, 0xF7, 0xD7, 0xFB, 0xD7, 0xF7, 0xF7, 0xF7,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
    ];
    private bool $demo = false;
    private int $demoFrames = 0;
    private float $demoClock = 0;
    private int $demoPrevious = 0xFF;
    private ?int $specialReturn = null;

    private function registerEvents()
    {
        $this->levelManager->on('levelChanged', fn($level) => $this->levelReload($level));

        // Connecte le CollisionSystem à Scene pour les événements de debug
        $this->scene->listenToCollisionEvents($this->collisionSystem);

        $this->collisionSystem->setObjectGroundProvider(
            fn(array $rect, float $vy, string $state) => $this->objectManager->findObjectGround($rect, $vy, $state)
        );
        $this->collisionSystem->setWaterLineProvider(fn() => $this->objectManager->getWaterLine());
        // the multiplayer Sonics use the same CollisionSystem: their hazards / pits go to the match
        $this->collisionSystem->on('playerHazard', fn($entity) => $entity === $this->player
            ? $this->objectManager->hurtPlayer($this->player) : $this->versus?->entityHazard($entity));
        $this->collisionSystem->on('paletteFlash', fn() => $this->objectManager->flashPalette());
        $this->collisionSystem->on('playerFell', fn($entity) => $entity === $this->player
            ? $this->objectManager->playerFellInPit($this->player) : $this->versus?->entityFell($entity));
        // multiplayer race: passing the sign ends the race for Sonic, no tally
        $this->objectManager->on('levelComplete', fn() => $this->versus ? $this->versus->humanFinished() : $this->screens->showActComplete(
            $this->levelManager->getCurrentLevel()->getLevel(),
            $this->objectManager->getScore(),
            $this->objectManager->getRingCount(),
            $this->objectManager->getLevelTime(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds()
        ));
        // Sound: every part of the engine emits 'sfx' / 'music' events
        // the multiplayer Sonics' springs, bumpers... are silent (Versus plays its own sounds)
        $playSfx = fn(int $index, bool $loop = false) => $this->versus?->isSimulating() ? null : $this->soundManager->playSfx($index, $loop);
        $this->player->on('sfx', $playSfx);
        $this->collisionSystem->on('sfx', $playSfx);
        $this->scene->on('sfx', $playSfx);
        $this->objectManager->on('sfx', $playSfx);
        $this->objectManager->on('stopSfx', fn(int $index) => $this->soundManager->stopSfx($index));
        $this->objectManager->on('music', fn(string $name, bool $loop) => $this->soundManager->playMusic($name, $loop, true));
        $this->objectManager->on('levelMusic', fn() => $this->playLevelMusic(true));
        $this->objectManager->on('extraLife', fn() => $this->soundManager->playSfx(SoundManager::SFX_EXTRA_LIFE));
        // ghost: a completed act is kept when it is the fastest; a restart from a checkpoint is not recorded
        // best times: the act timer when it is completed (not with cheats)
        $this->objectManager->on('levelComplete', fn() => $this->recordActTime());
        $this->objectManager->on('teleportEnd', fn() => $this->recordActTime());
        $this->objectManager->on('levelComplete', fn() => $this->objectManager->hasCheats() ? null : $this->ghost->finish(
            $this->ghostKey(), $this->objectManager->getLevelTime()));
        $this->objectManager->on('teleportEnd', fn() => $this->objectManager->hasCheats() ? null : $this->ghost->finish(
            $this->ghostKey(), $this->objectManager->getLevelTime()));
        $this->objectManager->on('playerRespawned', fn() => $this->ghost->begin($this->ghostKey(), !$this->objectManager->hasCheckpoint()));
        $this->objectManager->on('playerRespawned', function () {
            if (!$this->screens->isActive()) $this->playLevelMusic(true);   // not after a game over (title)
            $this->versus?->humanRespawned();
        });

        // _2067: once $D287 has run out the screen fades out; 60 black frames, then the act restarts
        $this->objectManager->on('deathOver', fn() => $this->fadeLevelOut(0x3C, function () {
            // the act is loaded again like at its start (_1CED): camera, scrolling, Sonic
            $this->player->resetForStart();
            $this->objectManager->respawnPlayer($this->player);
            $this->scene->resetLevel();
        }));
        $this->objectManager->on('gameOver', fn(int $continues) => $this->fadeLevelOut(0, fn() => $this->screens->showGameOver($continues)));
        $this->screens->on('gameOverDone', function (bool $continued) {
            if ($continued) {
                // the act from its beginning, with its map
                $this->objectManager->useContinue();
                $this->levelReload($this->levelManager->getCurrentLevel()->getLevel());
                $this->startLevel();
                return;
            }
            $this->actTimeBefore = 0;
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->levelReload(1);   // also when the game ended in act 1
            $this->screens->showTitle();
        });

        // end of the game (level 19): island, final tally and credits; then back to the title, new game
        $this->objectManager->on('gameEnd', fn() => $this->screens->showEnding(
            $this->objectManager->getScore(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds(),
            $this->objectManager->getStats()
        ));
        $this->screens->on('creditsDone', function () {
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->screens->showTitle();
        });

        // Title -> map -> level -> act complete -> map -> next level...
        $this->screens->on('titleDone', function () {
            $this->actTimeBefore = 0;
            $this->startGameCheats();
            $this->startLevel();
        });
        $this->screens->on('titleDemo', function () {
            // the demo is played without the cheats
            $this->objectManager->setCheats([]);
            $this->objectManager->setGodMode(false);
            $this->player->setGodLook(false);
            $this->demo = true;
            $this->demoFrames = 0;
            $this->demoClock = 0;
            $this->demoPrevious = 0xFF;
            $this->objectManager->resetGame();
            $this->levelManager->firstLevel();
            $this->startLevel();
        });
        $this->screens->on('mapDone', fn() => $this->playLevelMusic(true));
        $this->screens->on('versusStart', fn() => $this->startVersus());
        // a new best time without a name yet: the name is asked after the tally (or the Sky Base 3 teleporter)
        $this->screens->on('nameDone', function (string $name) {
            $this->playerName = $name;
            if ($this->pendingName !== null) {
                $this->bestTimes->setName($this->pendingName[0], $this->pendingName[1], $name);
            }
            $this->pendingName = null;
            $then = $this->afterName;
            $this->afterName = null;
            $then && $then();
        });
        $this->screens->on('actCompleteDone', fn() => $this->askNameThen(fn() => $this->afterActComplete()));
    }

    /** Shows the name entry when a best time is waiting for it, then goes on with $then. */
    private function askNameThen(\Closure $then): void
    {
        if ($this->pendingName === null) {
            $then();
            return;
        }
        [$act, $index, $time] = $this->pendingName;
        $this->afterName = $then;
        $this->screens->showNameEntry(BestTimes::place($act), $index + 1, BestTimes::format($time));
    }

    /** After the tally: the next special stage, the act after a transporter room, or the next act. */
    private function afterActComplete(): void
    {
        $this->actTimeBefore = 0;
        $this->objectManager->setScore($this->screens->getScore());
        $current = $this->levelManager->getCurrentLevel()->getLevel();
        // _1fa9: the sign showed "!": the next special stage ($D23F) is played, then the next act
        if ($this->objectManager->takeSpecialStage() && $this->nextSpecialStage <= 35) {
            $this->specialReturn = $this->warpFrom ?? $current;
            $this->warpFrom = null;
            $this->levelManager->goToLevel($this->nextSpecialStage++);
            $this->startLevel();
            return;
        }
        if ($this->specialReturn !== null && $current >= 28) {
            $this->levelManager->goToLevel($this->specialReturn);
            $this->specialReturn = null;
        }
        // after a transporter room the game goes on from the act that led there
        if ($this->warpFrom !== null) {
            $this->levelManager->goToLevel($this->warpFrom);
            $this->warpFrom = null;
        }
        $this->levelManager->nextLevel();
        $this->startLevel();
    }

    private function registerLevelEvents(): void
    {
        // Sky Base 3 teleporter ($FF): fade out, then the next level (the end) without tally
        $this->objectManager->on('teleportEnd', fn() => $this->fadeLevelOut(0, fn() => $this->askNameThen(function () {
            $this->actTimeBefore = 0;
            $this->warpFrom = null;
            $this->levelManager->nextLevel();
            $this->startLevel();
        })));
        // Scrap Brain transporter: the target room starts at once, without tally
        $this->objectManager->on('warp', function (int $level) {
            $this->actTimeBefore += $this->objectManager->getLevelTime();
            $this->warpFrom ??= $this->levelManager->getCurrentLevel()->getLevel();
            $this->levelManager->goToLevel($level);
            $this->playLevelMusic(true);
        });
    }

    /** fadeOut over the level ($0A6E: 4 steps of 10 frames, the game stopped), then $black frames, then $then. */
    private ?array $levelFade = null;
    private float $levelFadeClock = 0;

    private function fadeLevelOut(int $black, \Closure $then): void
    {
        if ($this->levelFade !== null) return;
        $this->levelFade = [0, $black, $then];
        $this->levelFadeClock = 0;
    }

    /** Darkness of the level fade, 0 (none) to 3 (black). */
    private function levelFadeStep(): int
    {
        return $this->levelFade === null ? 0 : min(3, intdiv($this->levelFade[0], Screens::FADE_STEP) + 1);
    }

    /** Back to the title screen after the demo (sound and screen faded out, _20b8), with a new game. */
    private function endDemo(): void
    {
        if ($this->levelFade !== null) return;
        $this->soundManager->stopMusic();
        $this->fadeLevelOut(0, fn() => $this->closeDemo());
    }

    /** A match against the computer Sonics (title menu > MULTIJOUEUR > LANCER). */
    private function startVersus(): void
    {
        $this->clearVersus();
        $this->actTimeBefore = 0;
        $this->warpFrom = null;
        $this->specialReturn = null;
        $this->objectManager->resetGame();
        $this->objectManager->setCheats(['lives' => true]);
        $this->objectManager->setGodMode(false);
        $this->player->setGodLook(false);
        $this->objectManager->noEnemies = true;
        $level = $this->options->getVersusLevel();
        if ($level !== $this->levelManager->getCurrentLevel()->getLevel()) {
            $this->levelManager->goToLevel($level);
        } else {
            $this->levelReload($level);
        }
        $this->player->resetForStart();
        $this->versus = new Versus($this->sdl, $this->collisionSystem, $this->objectManager, $this->scene->getCamera(),
            $this->player, $this->options->getVersusMode(),
            $this->options->getVersusMode() === 'flag' ? 2 * $this->options->getVersusTeamSize() : $this->options->getVersusPlayers(),
            $this->options->getVersusDifficulty());
        $this->versus->on('sfx', fn(int $index) => $this->soundManager->playSfx($index));
        $this->versus->on('music', fn(string $name, bool $loop) => $this->soundManager->playMusic($name, $loop, true));
        $this->scene->worldOverlay = fn(float $x, float $y) => $this->versus?->drawWorld($x, $y);
        $this->playLevelMusic(true);
    }

    private function clearVersus(): void
    {
        $this->versus = null;
        $this->player->setSheet('hero');
        $this->scene->worldOverlay = null;
        $this->scene->playerBlink = false;
        $this->objectManager->ringWrap = true;
        $this->objectManager->keepTilemap = false;
        $this->objectManager->noEnemies = false;
    }

    /** Results seen: back to the multiplayer page of the menu. */
    private function endVersus(): void
    {
        $this->backToTitle();
        $this->screens->showTitle('multi');
    }

    /** Escape during a game: it is abandoned, back to the main menu with a new game ready (like a game over). */
    public function backToTitle(): void
    {
        $this->clearVersus();
        $this->levelFade = null;   // a death / end-of-demo fade must not go on behind the menu
        $this->soundManager->stopSfx(SoundManager::SFX_SIGN_SPIN);
        $this->demo = false;
        $this->pendingName = null;
        $this->afterName = null;
        $this->actTimeBefore = 0;
        $this->warpFrom = null;
        $this->specialReturn = null;
        $this->nextSpecialStage = 28;
        $this->objectManager->resetGame();
        $this->levelManager->firstLevel();
        $this->levelReload(1);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->screens->close();
        $this->screens->showTitle();
    }

    private function closeDemo(): void
    {
        $this->demo = false;
        $this->soundManager->stopMusic();
        $this->objectManager->resetGame();
        $this->levelManager->firstLevel();
        // the demo played act 1: load it again from the start
        $this->levelReload(1);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->screens->showTitle();
    }

    /** Joypad of the demo for this frame (60Hz). */
    private function playDemo(float $deltaTime): void
    {
        $this->demoClock += $deltaTime;
        while ($this->demoClock >= 1 / 60) {
            $this->demoClock -= 1 / 60;
            $this->demoFrames++;
        }
        $pad = self::DEMO_INPUT[intdiv($this->demoFrames, 32)] ?? null;
        if ($pad === null || $this->objectManager->isPlayerDead()) {
            $this->endDemo();
            return;
        }
        $locked = $this->objectManager->isLevelComplete() || $this->objectManager->isCutscene();
        if (!$locked) {
            if (!($pad & 0x04)) $this->player->moveLeft();
            if (!($pad & 0x08)) $this->player->moveRight();
            if (!($pad & 0x01)) $this->player->watchUp();
            if (!($pad & 0x02)) $this->player->roll();
        }
        $button = ($pad & 0x30) !== 0x30;
        $wasButton = ($this->demoPrevious & 0x30) !== 0x30;
        if ($button && !$wasButton && !$locked) $this->player->jump();
        if (!$button && $wasButton) $this->player->jumpRelease();
        $this->objectManager->setButtonHeld($button);
        $this->demoPrevious = $pad;
    }

    /**
     * Joypad of the autoplay for this frame (F7): alone, the boss, then the capsule, then the end of the act; in a
     * match, what a bot of his team would go after. The act counts as played with a cheat (no best time, no ghost).
     */
    private function playAutoplay(float $deltaTime): void
    {
        $this->objectManager->setAssisted();
        if ($this->objectManager->isPlayerDead() || $this->objectManager->isLevelComplete()
            || $this->objectManager->isCutscene() || ($this->versus?->isLocked() ?? false)) {
            $this->autoplay->reset();
            $this->autoplayRoute->reset();
            return;
        }
        $level = $this->scene->getCurrentLevel();
        // the collisions of a map are its surfaces: through one (a jump at full speed), Sonic falls inside the
        // ground for ever; back where he last stood
        if ($this->player->isGrounded()) {
            $this->autoplaySafe = [$this->player->getX(), $this->player->getY(), spl_object_id($level)];
            $this->autoplayAir = 0;
        } elseif (($this->autoplayAir += $deltaTime) > 3 && $this->autoplaySafe !== null && $this->autoplaySafe[2] === spl_object_id($level)) {
            $this->autoplayAir = 0;
            $this->player->setXY($this->autoplaySafe[0], $this->autoplaySafe[1]);
            $this->player->setVelocity(0, 0);
            $this->autoplay->reset();   // not the route: stuck there, it puts him further on the way
            return;
        }
        [$target, $chase, $attacking] = $this->versus?->autoplayTarget() ?? $this->objectManager->autoplayTarget($this->player, $level) + [2 => false];
        // after an opponent or the boss: straight at him (he moves; the arena of a boss holds Sonic in anyway)
        $way = $chase ? ['target' => $target, 'direct' => true, 'warp' => null]
            : $this->autoplayRoute->next($level, $this->player->getX(), $this->player->getY(), $target, $deltaTime);
        if ($way['warp'] !== null) {
            // stuck for long on the way: further on it
            $this->player->setXY($way['warp'][0], $way['warp'][1]);
            $this->player->setVelocity(0, 0);
            $this->autoplay->reset();
            return;
        }
        $input = $this->autoplay->think($this->player, $level, $way['target'], $deltaTime, $chase && $way['direct'], $attacking);
        if ($input['dir'] !== 0) $this->player->moveDirection($input['dir'] > 0 ? 'right' : 'left');
        if ($input['down']) $this->player->roll();
        // a badnik or spikes just ahead: a jump (on a badnik it destroys it)
        if (!$input['jump'] && $this->objectManager->autoplayDanger($this->player, $level, $input['dir'])) $input['jump'] = true;
        if ($input['jump'] && $this->player->isGrounded()) {
            $this->player->jump();
            if ($input['super']) $this->player->setVelocity($this->player->getVelocity()[0], -620);
            $this->autoplayJump = 0.4;
        }
        if ($this->autoplayJump > 0) {
            $this->autoplayJump -= $deltaTime;
            if ($this->autoplayJump <= 0) $this->player->jumpRelease();
        }
        $this->objectManager->setButtonHeld($this->autoplayJump > 0);
    }

    /** Act completed: its time goes to the best times (not with cheats); the page will open on this act. */
    private function recordActTime(): void
    {
        if ($this->demo || $this->objectManager->hasCheats()) return;
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $time = $this->actTimeBefore + $this->objectManager->getLevelTime();
        $added = $this->bestTimes->add($level, $time, $this->playerName ?? '');
        if ($added === null) return;
        [$act, $index] = $added;
        $this->screens->showTimesOf($act);
        // among the 5 best and no name yet this session: asked after the tally
        if ($index !== null && $this->playerName === null) {
            $this->pendingName = [$act, $index, $time];
        }
    }

    /**
     * New game from the title menu: the cheats of the options (invincible = F5 for the whole game, infinite
     * lives / continues, 50 rings, turbo, shield, 6 emeralds) and the act chosen to start with.
     */
    private function startGameCheats(): void
    {
        $this->objectManager->setCheats($this->options->getCheats());
        $this->objectManager->startGameCheats();
        if ($this->options->isCheatOn('invincible')) {
            $this->objectManager->setGodMode(true);
            $this->player->setGodLook(true);
        }
        $start = $this->options->getStartLevel();
        if ($start !== $this->levelManager->getCurrentLevel()->getLevel()) {
            $this->levelManager->goToLevel($start);
        }
    }

    /**
     * Ghost: Sonic's run is recorded on the level time (not in the demo, the special stages or while dying),
     * and the best run of the level is drawn when the option is on.
     */
    private function updateGhost(): void
    {
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $key = $this->ghostKey();
        $time = $this->objectManager->getLevelTime();
        $played = !$this->demo && !$this->versus && ($level < 28 || $key !== $level) && $this->scene->getCurrentLevel() === $this->levelManager->getCurrentLevel();
        // runs with cheats are not recorded
        if ($played && !$this->objectManager->hasCheats() && !$this->objectManager->isPlayerDead() && !$this->objectManager->isLevelComplete()) {
            $this->ghost->record($key, $time, $this->player->getX(), $this->player->getY(), $this->player->getFrameSnapshot());
        }
        $this->scene->setGhostFrame($played && $this->options->isGhostEnabled() ? $this->ghost->frameAt($key, $time) : null);
    }

    /** Whose ghost: the act's number. */
    private function ghostKey(): int
    {
        return $this->levelManager->getCurrentLevel()->getLevel();
    }

    /** F6 panel, in window pixels: level, Sonic's position (pixels and blocks), speed, camera, state, time. */
    private function drawCoordsPanel(int $windowHeight): void
    {
        $size = max(12, (int)round($windowHeight / 36));
        $x = (int)round($this->player->getX());
        $y = (int)round($this->player->getY());
        [$vx, $vy] = $this->player->getVelocity();
        $camera = $this->scene->getCamera();
        $time = $this->objectManager->getLevelTime();
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $best = $this->ghost->bestTime($this->ghostKey());
        $lines = [
            'FPS ' . $this->engine->fps->fps,
            'NIVEAU ' . $level,
            'X ' . $x . '   Y ' . $y,
            sprintf('BLOC %d,%d  (%02X,%02X)', intdiv($x, 32), intdiv($y, 32), intdiv($x, 32), intdiv($y, 32)),
            sprintf('VX %d  VY %d', (int)round($vx / 60 * 256), (int)round($vy / 60 * 256)),
            'CAM ' . $camera->getX() . ',' . $camera->getY(),
            'ETAT ' . strtoupper($this->player->getState()),
            sprintf('TEMPS %d:%05.2F', intdiv((int)$time, 60), fmod($time, 60)),
        ];
        if ($best !== null) $lines[] = sprintf('GHOST %d:%05.2F', intdiv((int)$best, 60), fmod($best, 60));
        $line = (int)round($size * 1.3);
        $width = max(array_map(fn($l) => $this->text->width($l, $size), $lines));
        $this->text->panel(8, 8, $width + $size * 2, count($lines) * $line + $size);
        foreach ($lines as $i => $text) {
            $this->text->draw($text, 8 + $size, 8 + (int)($size / 2) + $i * $line, $size, $i === 2 ? [255, 255, 0] : [255, 255, 255]);
        }
    }

    /** F7: AUTOPLAY at the bottom centre: the player sees that the computer is playing. */
    private function drawAutoplay(int $windowHeight): void
    {
        $size = max(12, (int)round($windowHeight / 30));
        $label = 'AUTOPLAY (F7)';
        $width = $this->text->width($label, $size);
        $x = intdiv($this->sdl->getRenderer()->outputSize()[0] - $width, 2);
        $y = $windowHeight - $size * 3;
        $this->text->panel($x - $size, $y - (int)($size / 2), $width + $size * 2, $size * 2);
        $this->text->draw($label, $x, $y, $size, [255, 216, 0]);
    }

    /** Shows the map before the acts that have one (levels 1-18), the level starts when it ends. */
    private function startLevel(): void
    {
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        if ($this->screens->hasMap($level)) {
            $this->screens->showMap($level, $this->objectManager->getLives());
        } else {
            $this->playLevelMusic(true);
        }
    }

    /**
     * Music of the current level from its header (MU byte, index into the driver's song list),
     * or the invincibility / boss song while they are active ($FF = keep the current song).
     */
    private function playLevelMusic(bool $restart = false): void
    {
        if ($this->objectManager->isInvincible()) {
            $this->soundManager->playMusic('invincibility', true, $restart);
        } elseif ($this->objectManager->isBossFight()) {
            $this->soundManager->playMusic('boss', true, $restart);
        } else {
            $index = $this->levelManager->getCurrentLevel()->getMeta('MU', 0);
            if ($index === 0xFF) return;
            $name = SoundManager::MUSIC_BY_INDEX[$index] ?? null;
            if ($name) $this->soundManager->playMusic($name, true, $restart);
        }
    }

    private function levelReload(int $level): void
    {
        $this->scene->resetLevel();
        $this->objectManager->resetForNewLevel();
    }


    /** Once, when the game starts (GameScene): sound, textures, levels, events. */
    public function boot(): void
    {
        $this->soundManager->Init();
        $this->screens->setSoundManager($this->soundManager);

        // Init Textures
        $this->player->initTexture($this->sdl);
        $sprites = [
            'objects' => ['r' => 170, 'g' => 0, 'b' => 170],
            'enemies' => ['r' => 170, 'g' => 0, 'b' => 170],
            'doctor' => ['r' => 0, 'g' => 170, 'b' => 255],
            'hero' => ['r' => 0, 'g' => 72, 'b' => 0],
            'flower' => ['r' => 170, 'g' => 0, 'b' => 170],   // background tiles (tools/flowers.py), opaque
            'rom' => ['r' => 170, 'g' => 0, 'b' => 170],      // sprites rendered from the ROM (tools/romsprites.py)
            'skyglass' => ['r' => 170, 'g' => 0, 'b' => 170], // Sky Base boss broken glass (tools/skybaseglass.py)
            'boss' => ['r' => 170, 'g' => 0, 'b' => 170],     // act 3 bosses from the ROM layouts (tools/bosssprites.py)
        ];
        foreach ($sprites as $name => $key) {
            if ($name !== 'hero') {
                $this->sdl->loadTexture($name, 'tileset/sprites/tileset-' . $name . '.png', $key);
            }
            // underwater palette (Labyrinth), see tools/waterpalette.py
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-water', $key);
            // boss hit: outlines flashing white
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-flash', $key);
        }
        $this->sdl->loadFont('hero', 'fonts/nise.ttf');
        $this->screens->loadTextures();

        $this->levelManager->loadLevels();
        $this->registerEvents();
        $this->registerLevelEvents();
    }

    /** The title screen, its menu (the start of the game). */
    public function showTitle(): void
    {
        $this->screens->showTitle();
    }

    /**
     * A new game straight in act $level, without the title nor the map (GameScene params: tests, screenshots).
     * $bot: the computer plays it (the autoplay of F7, kept from act to act).
     */
    public function startAt(int $level, bool $bot = false): void
    {
        $this->screens->close();
        $this->actTimeBefore = 0;
        $this->warpFrom = null;
        $this->specialReturn = null;
        $this->objectManager->setCheats([]);
        $this->objectManager->resetGame();
        if ($level !== $this->levelManager->getCurrentLevel()->getLevel()) {
            $this->levelManager->goToLevel($level);
        } else {
            $this->levelReload($level);
        }
        $this->player->resetForStart();
        $this->playLevelMusic(true);
        if ($bot) $this->setAutoplay(true, true);
    }

    /** A match against the computer Sonics straight away (GameScene params): $mode (Versus::MODES), act $level. */
    public function startMatchAt(string $mode, int $level, int $players, bool $bot = false): void
    {
        $this->options->setVersus($mode, $players, $level);
        $this->screens->close();
        $this->startVersus();
        if ($bot) $this->setAutoplay(true, true);
    }

    /** The demo of the title screen (the ROM's joypad on Green Hill 1). */
    public function startDemo(): void
    {
        $this->screens->close();
        $this->screens->emit('titleDemo', []);
    }

    /** F7 (or the scene's bot): the computer plays for Sonic, a BotBrain on his joypad and a BotRoute for the way. */
    private function setAutoplay(bool $on, bool $kept = false): void
    {
        $this->autoplay = $on ? new BotBrain(2) : null;
        $this->autoplayRoute = $on ? new BotRoute() : null;
        $this->autoplayJump = 0;
        $this->autoplayKept = $on && $kept;
        $this->player->jumpRelease();
    }

    /**
     * One frame of the game ($deltaTime: real seconds since the last one), what the original loop's timer did:
     * the joypad, then the screens or the act (Sonic's physics in steps of at most 1/120 s, the objects, the camera).
     */
    public function frame(float $deltaTime, Pad $pad): void
    {
        $this->pad = $pad;
        // Options > VITESSE: PAL (Europe, 50 Hz) = the whole game at 50/60 of the NTSC speed
        $deltaTime *= $this->options->getTimeScale();

        $this->handleInput();
        // the debug views (F1) show the blocks touched by Sonic (forgotten after 1 s, cleaned every 0.1 s)
        $this->collisionSystem->reportTiles = $this->debugMode !== 0;
        if (($this->cleanupClock += $deltaTime) >= 0.1) {
            $this->cleanupClock = 0;
            $this->scene->cleanupOldCollisionTiles();
        }
        $this->soundManager->update();

        // the level is fading out: the game waits (fadeOut runs between interrupts only)
        if ($this->levelFade !== null && !$this->screens->isActive()) {
            $this->levelFadeClock += $deltaTime;
            while ($this->levelFade !== null && $this->levelFadeClock >= 1 / 60) {
                $this->levelFadeClock -= 1 / 60;
                if (++$this->levelFade[0] >= 4 * Screens::FADE_STEP + $this->levelFade[1]) {
                    $then = $this->levelFade[2];
                    $this->levelFade = null;
                    $then();
                }
            }
            $this->buttonPressed = false;
            return;
        }
        $this->levelFade = null;

        // demo mode: the joypad of the ROM (may end the demo and go back to the title screen)
        if ($this->demo && !$this->screens->isActive() && $this->scene->getCurrentLevel()) {
            $this->playDemo($deltaTime);
        }

        if ($this->screens->isActive()) {
            $this->screens->update($deltaTime, $this->buttonPressed);
            $this->buttonPressed = false;
            return;
        }

        if ($this->autoplay && !$this->demo && !$this->carryPlayer && $this->scene->getCurrentLevel()) {
            $this->playAutoplay($deltaTime);
        }

        if ($this->scene->getCurrentLevel() && $this->carryPlayer) {
            // debug (keypad 5): Sonic is carried at the centre of the free camera, no physics
            $camera = $this->scene->getCamera();
            $this->player->setXY(
                $camera->getX() + $this->sdl->getWindow()->getWidth() / 2 - 12,
                $camera->getY() + $this->sdl->getWindow()->getHeight() / 2 - 16
            );
            $this->player->setVelocity(0, 0);
            $this->player->setAcceleration(0, 0);
            $this->player->updateSprite($deltaTime);
            $this->objectManager->update($deltaTime, $this->player, $this->scene->getCurrentLevel());
        } elseif ($this->scene->getCurrentLevel()) {
            // after the sign: the joypad is forced to the right (flags6 bit 1)
            if ($this->objectManager->signRun($this->player)) {
                $this->player->moveRight();
            }
            if (!$this->objectManager->isPlayerDead()) {
                // after a slow frame the real delta can be large: move Sonic in steps of at most 1/120s
                // so that he can't go through a floor or a wall in one go (seen in the bonus stages)
                $steps = min(12, max(1, (int)ceil($deltaTime * 120 - 0.01)));
                // the joypad of this frame holds for every step (update() clears the flag)
                $moving = $this->player->isMovingFromInput;
                $look = $this->player->getLookRequest();
                for ($i = 0; $i < $steps; $i++) {
                    $this->player->isMovingFromInput = $moving;
                    $this->player->setLookRequest($look);
                    $this->player->update($deltaTime / $steps);
                    $this->collisionSystem->checkCollisions($this->player, $this->scene->getCurrentLevel(), $deltaTime / $steps);
                }
            } else {
                $this->player->updateSprite($deltaTime);
            }
            $this->objectManager->setRomCamera($this->scene->getCamera()->getRomCamera($this->player->getX(), $this->player->getY()));
            $window = $this->sdl->getWindow();
            $this->objectManager->setView($this->scene->getCamera()->getX(), $this->scene->getCamera()->getY(), $window->getWidth(), $window->getHeight());
            $this->objectManager->setWave($this->scene->getCamera()->getWave());
            $this->objectManager->update($deltaTime, $this->player, $this->scene->getCurrentLevel());
            $this->updateGhost();
            if ($this->versus) {
                $this->versus->update($deltaTime, $this->scene->getCurrentLevel());
                $this->scene->playerBlink = $this->versus->isHumanBlinking();
            }

            // speed shoes: the song plays faster while they last (the driver's tickDivider1 + 1)
            $this->soundManager->setFast($this->objectManager->hasSpeedShoes() && !$this->objectManager->isPlayerDead());

            // a jingle (emerald...) has finished: back to the level music
            if (!$this->objectManager->isPlayerDead() && !$this->objectManager->isLevelComplete()
                && !$this->soundManager->isMusicPlaying()) {
                $this->playLevelMusic();
            }

            // Boss arena: camera locked like the original, Sonic kept inside the arena
            $this->scene->getCamera()->lock = $this->objectManager->getCameraLock();
            if ($signLimits = $this->objectManager->getSignLimits()) {
                $this->scene->getCamera()->applySignLimits($signLimits[0], $signLimits[1]);
            }
            if ($bounds = $this->objectManager->getPlayerBounds()) {
                $x = $this->player->getX();
                if ($x < $bounds[0] || $x > $bounds[1]) {
                    $this->player->setXY(max($bounds[0], min($bounds[1], $x)), $this->player->getY());
                    $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                }
            }

            // Level header limits: Sonic stays between LEFT + 8 and RIGHT + 224 (pushed by auto-scrolling)
            // and dies once he falls below the original screen
            $camera = $this->scene->getCamera();
            if (!$this->objectManager->isPlayerDead() && $this->scene->getCurrentLevel() === $this->levelManager->getCurrentLevel()
                && !$this->objectManager->isSignRun()) {
                [$minX, $maxX] = $camera->getPlayerLimits();
                $x = $this->player->getCollisionRect()['x'];
                if ($x < $minX || $x > $maxX) {
                    $this->player->setX(max($minX, min($maxX, $x)));
                    $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                }
                if ($this->player->getCollisionRect()['y'] > $camera->getDeathLine($this->sdl->getWindow()->getHeight())) {
                    $this->objectManager->playerFellInPit($this->player);
                }
            }
            $this->player->settleFrame();
        }

        $this->scene->getCamera()->update($deltaTime);
    }

    /** The game view (JumPHP's view texture is the target): the act, its sprites, the HUD, the level fade. */
    public function render(): void
    {
        // the title, the menus, the tallies are drawn at window resolution (renderWindow)
        if ($this->screens->isActive()) return;
        $this->scene->setDebugMode($this->debugMode);
        $this->sdl->getRenderer()->setColor(0, 0, 0, 255);
        $this->sdl->getRenderer()->clear();
        $this->sdl->getRenderer()->createScene(
            $this->scene,
            $this->player,
            $this->sdl->getFont('hero'),
            $this->levelManager->getCurrentLevel()
        );
        // level fade: fade.png, black at 1/3, 2/3, 3/3
        if (($step = $this->levelFadeStep()) > 0) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = [($step - 1) * 8, 8, 8, 8];
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $this->sdl->getWindow()->getWidth(), $this->sdl->getWindow()->getHeight()];
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('screen-fade')[0]['texture'], $src, $dst);
        }
    }

    /**
     * What is drawn on the window at its own resolution, after JumPHP scaled the view into it (a renderer
     * overlay): the title / menus / tallies (whole scale of 256x192: sharp texts on any screen), the multiplayer
     * scores, the CRT filter, the debug panels.
     */
    public function renderWindow(): void
    {
        $view = $this->sdl->getRenderer();
        [$width, $height] = $view->outputSize();
        if ($width <= 0 || $height <= 0) return;
        $this->text->onWindow = true;
        if ($this->screens->isActive()) {
            $view->setColor(0, 0, 0, 255);
            $view->clear();
            $this->screens->render($width, $height);
            if ($this->options->isCrtEnabled()) {
                $dst = new \SDL_Rect;
                [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $width, $height];
                $this->crt ??= new CrtFilter($view);
                $this->crt->draw($dst, $this->sdl->getWindow()->getHeight());
            }
            $this->text->onWindow = false;
            return;
        }
        // where JumPHP put the view in the window
        [$x, $y, $w, $h] = $view->letterbox();
        $dst = new \SDL_Rect;
        [$dst->x, $dst->y, $dst->w, $dst->h] = [$x, $y, $w, $h];
        $scale = $w / max(1, $this->sdl->getWindow()->getWidth());
        if ($this->versus) {
            $this->versus->drawOverlay($this->text, $dst, $scale, $height);
        }
        if ($this->options->isCrtEnabled()) {
            $this->crt ??= new CrtFilter($view);
            $this->crt->draw($dst, $this->sdl->getWindow()->getHeight());
        }
        if ($this->coordsPanel) {
            $this->drawCoordsPanel($height);
        }
        if ($this->autoplay && !$this->autoplayKept) {
            $this->drawAutoplay($height);
        }
        if ($this->paused) {
            $this->drawPause($width, $height);
        }
        $this->text->onWindow = false;
    }

    // ------------------------------------------------------------------ pause (JumPHP)

    /** The game is frozen under App\Scene\PauseScene (the button, or the page / window hidden). */
    public bool $paused = false;
    private bool $pauseRequest = false;

    /** The pause button was pressed this frame (GameScene pushes PauseScene). */
    public function takePauseRequest(): bool
    {
        $request = $this->pauseRequest;
        $this->pauseRequest = false;
        return $request;
    }

    /** Only an act being played can be paused (not the menus, the map, the tallies, the demo, the results). */
    public function canPause(): bool
    {
        return !$this->screens->isActive() && !$this->demo && $this->levelFade === null && !($this->versus?->isOver() ?? false);
    }

    private function drawPause(int $width, int $height): void
    {
        $size = max(16, (int)round($height / 16));
        $label = Lang::t('PAUSE');
        $w = $this->text->width($label, $size);
        $this->text->panel(intdiv($width - $w, 2) - $size, intdiv($height, 2) - $size, $w + $size * 2, $size * 2 + (int)($size / 2));
        $this->text->draw($label, intdiv($width - $w, 2), intdiv($height, 2) - (int)($size * 0.6), $size, [255, 255, 85]);
    }

    /**
     * The joypad of this frame (the original read it while a key was down): the menus, the name entry, Sonic,
     * Escape (back to the title), the debug keys of the original (F1-F7, keypad).
     */
    private function handleInput(): void
    {
        $pad = $this->pad;
        if (!$pad->active()) return;
        if ($this->demo && ($pad->pressed('jump') || $pad->pressed('start'))) {
            $this->endDemo();
            return;
        }
        if ($this->screens->isActive()) {
            // title menu (start / options), or the on-screen keyboard of a new best time
            $naming = $this->screens->isNameEntry();
            // Enter: OK on the keyboard (Space types the key under the cursor)
            if ($pad->pressed('jump') || (!$naming && $pad->pressed('start'))) {
                $this->buttonPressed = true;
            }
            if ($naming && $pad->pressed('start')) $this->screens->nameKey('ok');
            foreach (['up', 'down', 'left', 'right'] as $direction) {
                if (!$pad->pressed($direction)) continue;
                $naming ? $this->screens->nameKey($direction) : $this->screens->menuInput($direction);
            }
            if ($naming) {
                // the real keyboard also types: A-Z, 0-9, backspace
                foreach ($pad->typed() as $code) {
                    if (($code >= ord('a') && $code <= ord('z')) || ($code >= ord('0') && $code <= ord('9')) || $code === ord('.') || $code === ord('-')) {
                        $this->screens->nameKey(chr($code));
                    } elseif ($code === \SDLK_BACKSPACE) {
                        $this->screens->nameKey('back');
                    }
                }
            }
            if ($pad->pressed('back')) {
                // the main menu quits (desktop); its pages go back; the other screens (map, tally...) go to the menu
                match ($this->screens->back()) {
                    'quit' => Platform::isDesktop() ? $this->engine->quit() : null,
                    'title' => $this->backToTitle(),
                    default => null,
                };
            }
            return;
        }

        if ($this->demo) {
            // only Escape below the demo's own joypad
            if ($pad->pressed('back')) $this->endDemo();
            return;
        }
        // multiplayer results: the button goes back to the menu
        if ($this->versus?->isOver() && ($pad->pressed('jump') || $pad->pressed('start'))) {
            $this->endVersus();
            return;
        }
        // the PAUSE button of the console (JumPHP: App\Scene\PauseScene over the game)
        if ($pad->pressed('start') && $this->canPause()) {
            $this->pauseRequest = true;
            return;
        }
        $this->objectManager->setButtonHeld($pad->held('jump'));
        $this->player->resetIdle();
        $playerLocked = $this->objectManager->isPlayerDead() || $this->objectManager->isLevelComplete()
            || $this->objectManager->isCutscene() || ($this->versus?->isLocked() ?? false);

        if ($playerLocked) {
            // no movement while dying or after the signpost
        } elseif ($pad->held('right') && $pad->held('left')) {
            // both: the last one pressed
            $pad->lastHorizontal() === 'right' ? $this->player->moveRight() : $this->player->moveLeft();
        } else {
            if ($pad->held('right')) $this->player->moveRight();
            if ($pad->held('left')) $this->player->moveLeft();
        }

        // up & down like left & right
        if ($pad->held('up') && $pad->held('down')) {
            $pad->lastVertical() === 'up' ? $this->player->watchUp() : $this->player->watchDown();
        } else {
            if ($pad->held('up')) $this->player->watchUp();
            if ($pad->held('down')) $this->player->roll();
        }

        if ($pad->pressed('jump') && !$playerLocked) {
            $this->player->jump();
        }
        if ($pad->released('jump')) {
            $this->player->jumpRelease();
        }

        if ($pad->pressed('back')) {
            // back to the main menu (Escape there quits the game on desktop)
            $this->backToTitle();
            return;
        }

        // the debug keys of the original
        if ($pad->key(\SDLK_F1)) {
            $this->debugMode = ($this->debugMode == 0 || $this->debugMode == 2) ? 1 : 0;
        }
        if ($pad->key(\SDLK_F2)) {
            $this->debugMode = match ($this->debugMode) { 2 => 3, 3 => 4, 4 => 2, default => 2 };
        }
        if ($pad->key(\SDLK_F5)) {
            // invincible against everything (enemies, traps, pits, drowning), Sonic with red eyes
            $this->player->setGodLook($this->objectManager->toggleGodMode());
        }
        if ($pad->key(\SDLK_F6)) {
            // Sonic's coordinates in a panel over the screen
            $this->coordsPanel = !$this->coordsPanel;
        }
        if ($pad->key(\SDLK_F7)) {
            // the computer plays for Sonic (alone, against the computer)
            $this->setAutoplay($this->autoplay === null);
        }
        if ($pad->key(\SDLK_F4)) {
            // next level, with its music
            $this->levelManager->nextLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }
        if ($pad->key(\SDLK_F3)) {
            // previous level, with its music
            $this->levelManager->previousLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }

        // Free camera on the keypad (2 4 6 8), kept inside the map; 5 toggles "Sonic follows the camera"
        $camera = $this->scene->getCamera();
        $moves = [\SDLK_KP_2 => [0, 10], \SDLK_KP_8 => [0, -10], \SDLK_KP_4 => [-10, 0], \SDLK_KP_6 => [10, 0]];
        foreach ($moves as $key => [$dx, $dy]) {
            if ($pad->keyHeld($key)) {
                $camera->disableStick = true;
                $this->moveDebugCamera($dx, $dy);
            }
        }
        if ($pad->key(\SDLK_KP_5)) {
            $this->carryPlayer = !$this->carryPlayer;
            // on: the camera stays where it is and carries Sonic; off: it follows Sonic again
            $camera->disableStick = $this->carryPlayer;
            if (!$this->carryPlayer) {
                $this->player->setVelocity(0, 0);
            }
        }
        if ($pad->key(\SDLK_KP_PLUS)) {
            // zoom in: bigger pixels, fewer tiles
            $this->changeZoom(1);
        }
        if ($pad->key(\SDLK_KP_MINUS)) {
            // zoom out: more tiles on screen
            $this->changeZoom(-1);
        }
        if ($pad->key(\SDLK_KP_MULTIPLY)) {
            // the 256x192 screen of the Master System, scaled to fit (on / off)
            $this->smsView = !$this->smsView;
        }
    }

    private function changeZoom(int $step): void
    {
        if ($this->smsView) {
            $this->smsView = false;   // leave the 256x192 screen at the zoom it looks like
            $this->zoomOffset = $this->zoomIndexFor($this->outputHeight() / 192) - $this->zoomIndexFor($this->outputHeight() / $this->viewHeight);
            return;
        }
        $auto = $this->zoomIndexFor($this->outputHeight() / $this->viewHeight);
        $this->zoomOffset = max(-$auto, min(count(self::ZOOMS) - 1 - $auto, $this->zoomOffset + $step));
    }

    private function zoomIndexFor(float $zoom): int
    {
        $best = 0;
        foreach (self::ZOOMS as $i => $z) {
            if (abs($z - $zoom) < abs(self::ZOOMS[$best] - $zoom)) {
                $best = $i;
            }
        }
        return $best;
    }

    private function outputHeight(): int
    {
        return max(1, $this->sdl->getRenderer()->outputSize()[1]);
    }

    /**
     * Sizes the game view to the window: same ratio as the window (more tiles on a wide screen, never
     * stretched sprites), or exactly 256x192 in Master System mode (black borders). JumPHP's view takes that
     * size (Renderer::resizeView): called between update and render, never while the view is the target.
     */
    public function updateView(): void
    {
        [$width, $height] = $this->sdl->getRenderer()->outputSize();
        if ($width <= 0 || $height <= 0) return;
        if ($this->smsView) {
            [$viewW, $viewH] = [256, 192];
        } else {
            $auto = $this->zoomIndexFor($height / $this->viewHeight);
            $index = max(0, min(count(self::ZOOMS) - 1, $auto + $this->zoomOffset));
            $zoom = self::ZOOMS[$index];
            [$viewW, $viewH] = [(int)round($width / $zoom), (int)round($height / $zoom)];
        }
        $window = $this->sdl->getWindow();
        if ($viewW === $window->getWidth() && $viewH === $window->getHeight()) return;
        $window->setViewSize($viewW, $viewH);
        $this->sdl->getRenderer()->resizeTarget($viewW, $viewH);
        $this->scene->getCamera()->noSmooth = true;   // re-centre at once in the new view
    }

    /** Free debug camera, kept inside the map. */
    private function moveDebugCamera(int $dx, int $dy): void
    {
        $camera = $this->scene->getCamera();
        $level = $this->levelManager->getCurrentLevel();
        $maxX = max(0, $level->getMapWidth() * 32 - $this->sdl->getWindow()->getWidth());
        $maxY = max(0, $level->getMapHeight() * 32 - $this->sdl->getWindow()->getHeight());
        $camera->setXY(max(0, min($maxX, $camera->getX() + $dx)), max(0, min($maxY, $camera->getY() + $dy)));
    }


}
