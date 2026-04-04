<?php
declare(strict_types=1);

/**
 * common.php
 * - Required by all logged-in pages (casino hub + games + api.php)
 * - Contains: shared helpers, shoe, credit helpers, slots engine, blackjack engine, baccarat engine,
 *   jackpot DB helpers, leaderboard helpers (if used), plus session state init.
 *
 * IMPORTANT:
 * - Do NOT redeclare auth/security functions that already exist elsewhere.
 * - enforce_idle_timeout() must be defined ONLY in auth.php.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';

session_boot();
ensure_csrf();

// Enforce login for all casino pages
require_login();

// Enforce idle timeout if the function exists (defined in auth.php)
if (function_exists('enforce_idle_timeout')) {
  enforce_idle_timeout();
}

/* ===========================
   CONFIG
=========================== */
const BJ_MAX_HISTORY  = 30;
const BAC_MAX_HISTORY = 60;

// Slots
const SLOT_MAX_HISTORY = 40;
const SLOT_DENOM       = 500; // 1 credit = 500
const SLOT_BET_MIN     = 1;
const SLOT_BET_MAX     = 2;

// Shoe (Blackjack default; Baccarat will override to 8 decks internally)
const SHOE_DECKS        = 6;
const SHOE_CUT_MIN_USED = 0.60;
const SHOE_CUT_MAX_USED = 0.75;

// Jackpot
const JACKPOT_SEED_AMOUNT = 250000;   // starting jackpot after win/reset
const JACKPOT_BET_RAKE_PCT = 0.02;     // portion of wager added to jackpot (2% example)

/* ===========================
   BASIC HELPERS
=========================== */
function nowIso2(): string {
  return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function clamp_int($value, int $min, int $max): ?int {
  $n = filter_var($value, FILTER_VALIDATE_INT);
  if ($n === false) return null;
  if ($n < $min || $n > $max) return null;
  return $n;
}

/* ===========================
   USER + CREDITS (DB-backed)
=========================== */
function user_row(): array {
  $u = current_user();
  if (!$u) throw new Exception("Not logged in.");
  return $u;
}

function credits(): int {
  $u = user_row();
  return (int)$u['credits'];
}

function can_wager(int $amt): bool {
  return $amt > 0 && credits() >= $amt;
}

/**
 * adjust_credits($delta)
 * - negative => subtract credits, add to total_wagered
 * - positive => add credits, add to total_won
 * Also updates session_high_credits.
 */
function adjust_credits(int $delta): void {
  $u = user_row();
  $uid = (int)$u['id'];

  $pdo = db();

  if ($delta < 0) {
    $pdo->prepare("
      UPDATE users
      SET credits = credits + :d,
          total_wagered = total_wagered + :w,
          updated_at = NOW()
      WHERE id = :id
    ")->execute([
      ':d' => $delta,
      ':w' => abs($delta),
      ':id'=> $uid
    ]);
  } else {
    $pdo->prepare("
      UPDATE users
      SET credits = credits + :d,
          total_won = total_won + :w,
          updated_at = NOW()
      WHERE id = :id
    ")->execute([
      ':d' => $delta,
      ':w' => $delta,
      ':id'=> $uid
    ]);
  }

  // Update session high if needed
  $st = $pdo->prepare("SELECT credits, session_high_credits FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch();
  if ($row) {
    $cur = (int)$row['credits'];
    $hi  = (int)$row['session_high_credits'];
    if ($cur > $hi) {
      $pdo->prepare("UPDATE users SET session_high_credits=?, updated_at=NOW() WHERE id=?")
        ->execute([$cur, $uid]);
    }
  }
}

/* ===========================
   SESSION GAME STATE
   - Game hands/history are session-based
   - Credits/jackpot are DB-backed
=========================== */
function init_state(): void {
  if (!isset($_SESSION['casino_state']) || !is_array($_SESSION['casino_state'])) {
    $_SESSION['casino_state'] = [
      'shoe' => shoe_init(SHOE_DECKS),

      'slot' => [
        'spins'=>0,'wins'=>0,'history'=>[],'last'=>null
      ],

      'bj' => [
        'phase'=>'idle',
        'bet'=>0,
        'insuranceBet'=>0,
        'hands'=>[],
        'activeHand'=>0,
        'dealer'=>[],
        'result'=>null,
        'history'=>[],
      ],

      'bac' => [
        'phase'=>'idle',
        'bet'=>[
          'player'=>0,'banker'=>0,'tie'=>0,
          'playerPair'=>0,'bankerPair'=>0,'perfectPair'=>0,
        ],
        'player'=>[],
        'banker'=>[],
        'result'=>null,
        'history'=>[],
        'shoe8' => null, // baccarat uses its own 8-deck shoe
      ],
    ];
  }
}
init_state();

/* ===========================
   SHOE / CARDS
=========================== */
function card_suits(): array { return ['♠','♥','♦','♣']; }
function card_ranks(): array { return ['A','2','3','4','5','6','7','8','9','10','J','Q','K']; }

function build_shoe(int $decks): array {
  $shoe = [];
  for ($d=0; $d<$decks; $d++) {
    foreach (card_suits() as $s) {
      foreach (card_ranks() as $r) {
        $shoe[] = ['r'=>$r,'s'=>$s];
      }
    }
  }
  shuffle($shoe);
  return $shoe;
}

function shoe_init(int $decks): array {
  $cards = build_shoe($decks);
  $total = count($cards);
  $usedPct = (mt_rand((int)(SHOE_CUT_MIN_USED*1000), (int)(SHOE_CUT_MAX_USED*1000)))/1000.0;
  $cutAtUsed = (int)floor($total * $usedPct);

  return [
    'decks'=>$decks,
    'cards'=>$cards,
    'total'=>$total,
    'drawn'=>0,
    'cutAtUsed'=>$cutAtUsed,
    'shuffles'=>1,
    'lastShuffle'=>nowIso2(),
  ];
}

function shoe_draw(array &$shoe): array {
  if (!isset($shoe['cards']) || !is_array($shoe['cards']) || count($shoe['cards']) === 0) {
    $shoe = shoe_init((int)($shoe['decks'] ?? SHOE_DECKS));
  }

  if (($shoe['drawn'] ?? 0) >= ($shoe['cutAtUsed'] ?? 0)) {
    $decks = (int)($shoe['decks'] ?? SHOE_DECKS);
    $shoe = shoe_init($decks);
    $shoe['shuffles'] = (int)($shoe['shuffles'] ?? 1) + 1;
  }

  $card = array_pop($shoe['cards']);
  $shoe['drawn'] = (int)($shoe['drawn'] ?? 0) + 1;
  return $card;
}

/* ===========================
   JACKPOT (DB-backed)
   - Requires table jackpot_state with id=1 row
=========================== */
function jackpot_get(): array {
  $pdo = db();
  $row = $pdo->query("SELECT * FROM jackpot_state WHERE id=1 LIMIT 1")->fetch();
  if (!$row) {
    $pdo->prepare("INSERT INTO jackpot_state (id, amount, seed_amount) VALUES (1, ?, ?)")
      ->execute([JACKPOT_SEED_AMOUNT, JACKPOT_SEED_AMOUNT]);
    $row = $pdo->query("SELECT * FROM jackpot_state WHERE id=1 LIMIT 1")->fetch();
  }

  return [
    'amount' => (int)$row['amount'],
    'seed' => (int)$row['seed_amount'],
    'lastWin' => $row['last_win_amount'] ? [
      'amount' => (int)$row['last_win_amount'],
      'userId' => (int)$row['last_win_user_id'],
      'ts' => (string)$row['last_win_at'],
    ] : null,
    'updated' => (string)$row['updated_at'],
  ];
}

function jackpot_add(int $inc): void {
  if ($inc <= 0) return;
  db()->prepare("UPDATE jackpot_state SET amount = amount + ?, updated_at=NOW() WHERE id=1")->execute([$inc]);
}

function jackpot_hit(int $userId): int {
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $st = $pdo->query("SELECT amount, seed_amount FROM jackpot_state WHERE id=1 FOR UPDATE");
    $row = $st->fetch();
    if (!$row) {
      $pdo->prepare("INSERT INTO jackpot_state (id, amount, seed_amount) VALUES (1, ?, ?)")
        ->execute([JACKPOT_SEED_AMOUNT, JACKPOT_SEED_AMOUNT]);
      $row = ['amount'=>JACKPOT_SEED_AMOUNT, 'seed_amount'=>JACKPOT_SEED_AMOUNT];
    }

    $award = (int)$row['amount'];
    $seed  = (int)$row['seed_amount'];

    $pdo->prepare("
      UPDATE jackpot_state
      SET amount = ?,
          last_win_amount = ?,
          last_win_user_id = ?,
          last_win_at = NOW(),
          updated_at = NOW()
      WHERE id=1
    ")->execute([$seed, $award, $userId]);

    $pdo->commit();
    return $award;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/* ===========================
   META PAYLOAD
=========================== */
function meta_payload(): array {
  $u = user_row();
  $shoe = $_SESSION['casino_state']['shoe'] ?? [];

  return [
    'credits' => (int)$u['credits'],
    'startCredits' => (int)$u['start_credits'],
    'netWon' => (int)$u['credits'] - (int)$u['start_credits'],
    'sessionHighCredits' => (int)$u['session_high_credits'],
    'shoe' => [
      'decks' => (int)($shoe['decks'] ?? SHOE_DECKS),
      'totalCards' => (int)($shoe['total'] ?? 0),
      'drawn' => (int)($shoe['drawn'] ?? 0),
      'remaining' => is_array($shoe['cards'] ?? null) ? count($shoe['cards']) : 0,
      'cutAtUsed' => (int)($shoe['cutAtUsed'] ?? 0),
      'shuffles' => (int)($shoe['shuffles'] ?? 1),
      'lastShuffle' => (string)($shoe['lastShuffle'] ?? ''),
    ],
    'user' => [
      'email' => (string)($u['email'] ?? ''),
      'isAdmin' => ((int)$u['is_admin'] === 1),
    ],
    'csrf' => (string)($_SESSION['csrf'] ?? ''),
  ];
}

/* ===========================
   LEADERBOARD (safe stub)
   - prevents "undefined function lb_payload"
   - You can wire it to DB later if desired
=========================== */
function lb_payload(): array {
  return [
    'todayTop3' => [],
    'allTimeTop3' => [],
  ];
}

/* ============================================================
   SLOTS ENGINE
   - 3 reels
   - VIP bet (2 credits) can trigger BONUS feature rarely
   - BONUS wheel only on bonus feature + VIP
   - Minimum bonus payout: 5x bet
   - Maximum: progressive jackpot (ONLY way jackpot can be won)
   - Jackpot grows by % of ALL wagers
============================================================ */
function slot_symbols(): array {
  return [
    'A' => ['🂡','Ace', 18],
    'K' => ['👑','King', 16],
    'Q' => ['💎','Gem', 14],
    '7' => ['7️⃣','Seven', 10],
    'B' => ['⭐','Star', 8],
    'W' => ['🟦','Wild', 5],
    'X' => ['🎁','Bonus', 2], // rarer
  ];
}

function slot_weighted_pick(array $symbols): string {
  $total = 0;
  foreach($symbols as $k=>$v) $total += (int)$v[2];
  $r = random_int(1, max(1,$total));
  $acc = 0;
  foreach($symbols as $k=>$v){
    $acc += (int)$v[2];
    if($r <= $acc) return (string)$k;
  }
  return (string)array_key_first($symbols);
}

function slot_spin_reels(): array {
  $sym = slot_symbols();
  return [
    slot_weighted_pick($sym),
    slot_weighted_pick($sym),
    slot_weighted_pick($sym)
  ];
}

/**
 * Returns base line profit multiplier.
 * (This is your base slot payout; the BONUS wheel is separate.)
 */
function slot_line_multiplier(array $r): int {
  $counts = array_count_values($r);
  $wilds = (int)($counts['W'] ?? 0);

  // Simple: 3 bonus symbols yields small base (bonus feature handled separately)
  if (($counts['X'] ?? 0) === 3) return 2;

  $keys = array_keys(slot_symbols());
  $best = 0;

  foreach($keys as $k){
    if($k==='W' || $k==='X') continue;
    $need = 3 - $wilds;
    $have = (int)($counts[$k] ?? 0);
    if($have >= $need){
      $mult = 0;
      if($k==='A') $mult = 2;
      if($k==='K') $mult = 3;
      if($k==='Q') $mult = 4;
      if($k==='7') $mult = 8;
      if($k==='B') $mult = 10;
      $best = max($best, $mult);
    }
  }

  // All wilds
  if($wilds === 3) $best = max($best, 12);

  // Any pair (including wilds) => small win
  if($best === 0){
    $pairHit = false;
    foreach($keys as $k){
      if($k==='W') continue;
      $have = (int)($counts[$k] ?? 0);
      if($have + $wilds >= 2) { $pairHit = true; break; }
    }
    if($pairHit) $best = 1;
  }

  return $best;
}

/**
 * Bonus feature trigger (rare):
 * - Must be VIP bet (2 credits)
 * - Require at least TWO bonus symbols OR (bonus+wild combos) to feel exciting
 * Tweak rules here as you like.
 */
function slot_bonus_triggered(array $reels, bool $vip): bool {
  if(!$vip) return false;
  $counts = array_count_values($reels);
  $x = (int)($counts['X'] ?? 0);
  $w = (int)($counts['W'] ?? 0);

  // Rare-ish: X X anything OR X + 2 wilds OR X X X
  if($x >= 2) return true;
  if($x === 1 && $w >= 2) return true;

  // Extra rarity: 1 in 40 chance even if you have one X
  if($x === 1 && random_int(1, 40) === 1) return true;

  return false;
}

/**
 * Bonus wheel segment builder:
 * - must guarantee min payout 5x bet
 * - jackpot is max and rare
 */
function slot_wheel_segments(): array {
  return [
    ['label'=>'x5',      'mult'=>5],
    ['label'=>'x6',      'mult'=>6],
    ['label'=>'x8',      'mult'=>8],
    ['label'=>'x10',     'mult'=>10],
    ['label'=>'x12',     'mult'=>12],
    ['label'=>'x15',     'mult'=>15],
    ['label'=>'x20',     'mult'=>20],
    ['label'=>'JACKPOT', 'mult'=>0], // special
  ];
}

/**
 * Weighted selection for bonus wheel.
 * Jackpot extremely rare.
 */
function slot_wheel_pick_index(): int {
  // 8 segments -> weights align to above
  $weights = [24, 20, 18, 14, 10, 7, 5, 2]; // jackpot weight=2
  $sum = array_sum($weights);
  $r = random_int(1, max(1,$sum));
  $acc = 0;
  foreach($weights as $i=>$w){
    $acc += $w;
    if($r <= $acc) return $i;
  }
  return 0;
}

function slot_reset_round(): void {
  $_SESSION['casino_state']['slot']['last'] = null;
}

function slot_payload(): array {
  $S = $_SESSION['casino_state']['slot'];
  $sp = (int)($S['spins'] ?? 0);
  $w  = (int)($S['wins'] ?? 0);
  $rate = $sp>0 ? round(($w/$sp)*100,1) : 0.0;

  return [
    'spins'=>$sp,
    'wins'=>$w,
    'winRate'=>$rate,
    'history'=>array_values($S['history'] ?? []),
    'last'=>$S['last'] ?? null,
    'symbols'=>slot_symbols(),
    'wheel'=>slot_wheel_segments(),
    'denom'=>SLOT_DENOM,
    'betOptions'=>[SLOT_BET_MIN, SLOT_BET_MAX],
    'jackpot'=>jackpot_get(),
  ];
}

/**
 * SLOT PLAY
 * - Adds % of wager to jackpot
 * - Base line payout handled by multiplier
 * - BONUS wheel ONLY when feature triggered
 * - Jackpot ONLY payable via bonus wheel on VIP
 */
function slot_play(int $betCredits): array {
  if($betCredits < SLOT_BET_MIN || $betCredits > SLOT_BET_MAX) {
    throw new Exception("Bet must be 1 or 2 credits.");
  }

  $u = user_row();
  $uid = (int)$u['id'];

  $wager = $betCredits * SLOT_DENOM;

  $before = credits();
  if(!can_wager($wager)) throw new Exception("Not enough credits.");
  adjust_credits(-$wager);

  // Jackpot grows on every wager (server-side)
  $jackpotInc = (int)max(1, floor($wager * JACKPOT_BET_RAKE_PCT));
  jackpot_add($jackpotInc);

  $reels = slot_spin_reels();
  $mult  = slot_line_multiplier($reels);

  $baseProfit = 0;
  $basePayoutTotal = 0;

  // Base payout (profit + stake returned)
  if($mult > 0){
    $baseProfit = $wager * $mult;
    $basePayoutTotal = $wager + $baseProfit;
    adjust_credits($basePayoutTotal);
  }

  $vip = ($betCredits === SLOT_BET_MAX);

  // BONUS WHEEL (rare trigger)
  $bonusTriggered = slot_bonus_triggered($reels, $vip);

  $wheel = null;
  $jackpotHit = false;
  $jackpotAward = 0;

  if($bonusTriggered){
    $wheelIndex = slot_wheel_pick_index();
    $segs = slot_wheel_segments();
    $picked = $segs[$wheelIndex] ?? ['label'=>'x5','mult'=>5];

    // If jackpot segment
    if(($picked['label'] ?? '') === 'JACKPOT'){
      $jackpotHit = true;
      $jackpotAward = jackpot_hit($uid);
      if($jackpotAward > 0) adjust_credits($jackpotAward);
      $wheel = ['index'=>$wheelIndex,'label'=>'JACKPOT','mult'=>0,'bonus'=>$jackpotAward];
    } else {
      $wheelMult = (int)($picked['mult'] ?? 5);
      if($wheelMult < 5) $wheelMult = 5;

      // Bonus payout = wager * wheelMult (minimum 5x bet)
      $wheelBonus = $wager * $wheelMult;
      if($wheelBonus > 0) adjust_credits($wheelBonus);

      $wheel = ['index'=>$wheelIndex,'label'=>$picked['label'],'mult'=>$wheelMult,'bonus'=>$wheelBonus];
    }
  }

  $after = credits();

  $S = &$_SESSION['casino_state']['slot'];
  $S['spins'] = (int)($S['spins'] ?? 0) + 1;
  if($mult > 0 || $bonusTriggered) $S['wins'] = (int)($S['wins'] ?? 0) + 1;

  $result = [
    'time'=>nowIso2(),
    'betCredits'=>$betCredits,
    'denom'=>SLOT_DENOM,
    'wager'=>$wager,
    'before'=>$before,
    'after'=>$after,
    'reels'=>$reels,
    'mult'=>$mult,
    'baseProfit'=>$baseProfit,
    'basePayoutTotal'=>$basePayoutTotal,
    'vip'=>$vip,
    'bonusTriggered'=>$bonusTriggered,
    'wheel'=>$wheel,
    'jackpotHit'=>$jackpotHit,
    'jackpotAward'=>$jackpotAward,
    'jackpotInc'=>$jackpotInc,
  ];

  $S['last'] = $result;
  array_unshift($S['history'], $result);
  $S['history'] = array_slice($S['history'], 0, SLOT_MAX_HISTORY);

  return $result;
}
/* ============================================================
   BLACKJACK ENGINE
============================================================ */

function bj_hand_value(array $hand): array {
  $total = 0; $aces = 0;
  foreach ($hand as $c) {
    $r = (string)($c['r'] ?? '');
    if ($r === 'A') { $aces++; $total += 11; }
    elseif (in_array($r, ['K','Q','J'], true)) $total += 10;
    else $total += (int)$r;
  }
  while ($total > 21 && $aces > 0) { $total -= 10; $aces--; }

  // Soft detection
  $base = 0; $a2 = 0;
  foreach ($hand as $c) {
    $r = (string)($c['r'] ?? '');
    if ($r === 'A') { $a2++; $base += 1; }
    elseif (in_array($r, ['K','Q','J'], true)) $base += 10;
    else $base += (int)$r;
  }
  $soft = ($a2 > 0 && ($base + 10) <= 21);
  return ['total'=>$total, 'soft'=>$soft];
}

function bj_is_blackjack(array $hand): bool {
  if (count($hand) !== 2) return false;
  $ten = fn($r) => in_array($r, ['10','J','Q','K'], true);
  $r1 = (string)$hand[0]['r'];
  $r2 = (string)$hand[1]['r'];
  return ($r1==='A' && $ten($r2)) || ($r2==='A' && $ten($r1));
}

function bj_can_split(array $hand): bool {
  return count($hand)===2 && ((string)$hand[0]['r'] === (string)$hand[1]['r']);
}

function bj_reset_round(): void {
  $prevHist = $_SESSION['casino_state']['bj']['history'] ?? [];
  $_SESSION['casino_state']['bj'] = [
    'phase'=>'idle',
    'bet'=>0,
    'insuranceBet'=>0,
    'hands'=>[],
    'activeHand'=>0,
    'dealer'=>[],
    'result'=>null,
    'history'=>$prevHist,
  ];
}

function bj_snapshot(): array {
  $bj = $_SESSION['casino_state']['bj'];
  $hands = $bj['hands'] ?? [];
  $dealer = $bj['dealer'] ?? [];
  $active = (int)($bj['activeHand'] ?? 0);

  $handTotals = [];
  foreach ($hands as $idx => $h) {
    // Hands can contain __meta; keep only card arrays
    $clean = array_values(array_filter($h, fn($x)=>is_array($x) && isset($x['r'])));
    $v = bj_hand_value($clean);
    $handTotals[] = ['idx'=>$idx,'total'=>$v['total'],'soft'=>$v['soft']];
  }
  $dv = bj_hand_value(array_values(array_filter($dealer, fn($x)=>is_array($x) && isset($x['r']))));

  $phase = (string)($bj['phase'] ?? 'idle');
  $hand = $hands[$active] ?? [];
  $cleanHand = array_values(array_filter($hand, fn($x)=>is_array($x) && isset($x['r'])));

  $canDouble = ($phase==='player' && count($cleanHand)===2);
  $canSplit  = ($phase==='player' && count($hands)===1 && bj_can_split($cleanHand));

  $dealerUp = $dealer[0] ?? null;
  $canInsurance = ($phase==='player'
    && is_array($dealerUp)
    && (($dealerUp['r'] ?? '') === 'A')
    && (int)($bj['insuranceBet'] ?? 0) === 0
  );

  return [
    'phase'=>$phase,
    'bet'=>(int)($bj['bet'] ?? 0),
    'insuranceBet'=>(int)($bj['insuranceBet'] ?? 0),
    'hands'=>$hands,
    'activeHand'=>$active,
    'dealer'=>$dealer,
    'handTotals'=>$handTotals,
    'dealerTotal'=>$dv['total'],
    'dealerSoft'=>$dv['soft'],
    'result'=>$bj['result'],
    'history'=>$bj['history'] ?? [],
    'flags'=>[
      'canDouble'=>$canDouble,
      'canSplit'=>$canSplit,
      'canInsurance'=>$canInsurance,
    ],
  ];
}

function bj_record(string $label): void {
  $bj = &$_SESSION['casino_state']['bj'];
  array_unshift($bj['history'], [
    'time'=>nowIso2(),
    'label'=>$label,
    'bet'=>(int)($bj['bet'] ?? 0),
    'insuranceBet'=>(int)($bj['insuranceBet'] ?? 0),
    'hands'=>$bj['hands'] ?? [],
    'dealer'=>$bj['dealer'] ?? [],
  ]);
  $bj['history'] = array_slice($bj['history'], 0, BJ_MAX_HISTORY);
}

function bj_dealer_play(): void {
  $bj = &$_SESSION['casino_state']['bj'];

  // Optional: read dealerHitsSoft17 from session state if you later store it
  $hitsSoft17 = (bool)($_SESSION['casino_state']['settings']['dealerHitsSoft17'] ?? false);

  while (true) {
    $cleanDealer = array_values(array_filter($bj['dealer'], fn($x)=>is_array($x) && isset($x['r'])));
    $dv = bj_hand_value($cleanDealer);
    $t = $dv['total']; $soft = $dv['soft'];

    if ($t < 17) { $bj['dealer'][] = shoe_draw($_SESSION['casino_state']['shoe']); continue; }
    if ($t === 17 && $soft && $hitsSoft17) { $bj['dealer'][] = shoe_draw($_SESSION['casino_state']['shoe']); continue; }
    break;
  }
}

function bj_resolve(): void {
  $bj = &$_SESSION['casino_state']['bj'];
  $bet = (int)($bj['bet'] ?? 0);

  $dealerCards = array_values(array_filter($bj['dealer'], fn($x)=>is_array($x) && isset($x['r'])));
  $dealerBJ = bj_is_blackjack($dealerCards);
  $dealerTotal = bj_hand_value($dealerCards)['total'];

  $ins = (int)($bj['insuranceBet'] ?? 0);
  $parts = [];

  if ($ins > 0) {
    if ($dealerBJ) {
      // Insurance pays 2:1 profit; total return = 3x
      adjust_credits($ins * 3);
      $parts[] = "Insurance won";
    } else {
      $parts[] = "Insurance lost";
    }
  }

  $hands = $bj['hands'] ?? [];
  foreach ($hands as $i => $hand) {
    $meta = is_array($hand) ? ($hand['__meta'] ?? []) : [];
    $cards = array_values(array_filter($hand, fn($x)=>is_array($x) && isset($x['r'])));

    $handBet = $bet;
    if (!empty($meta['double'])) $handBet = $bet * 2;

    $hv = bj_hand_value($cards);
    $pt = $hv['total'];

    $out = 'push';
    $payout = 0;

    if ($pt > 21) {
      $out = 'lose'; $payout = 0;
    } elseif ($dealerTotal > 21) {
      $out = 'win'; $payout = $handBet * 2;
    } elseif ($pt > $dealerTotal) {
      $out = 'win'; $payout = $handBet * 2;
    } elseif ($pt < $dealerTotal) {
      $out = 'lose'; $payout = 0;
    } else {
      $out = 'push'; $payout = $handBet;
    }

    $isBJ = (count($hands)===1 && empty($meta['double']) && bj_is_blackjack($cards));
    if ($isBJ && $out==='win') {
      // 3:2 payout total = 2.5x bet
      $payout = (int)round($bet * 2.5);
      $parts[] = "Blackjack 3:2";
    }

    if ($payout > 0) adjust_credits($payout);
    $parts[] = "Hand ".($i+1).": ".ucfirst($out);
  }

  $bj['result'] = ['label'=>implode(' • ', $parts ?: ['Round complete'])];
}

function bj_start(int $bet): void {
  if ($bet <= 0) throw new Exception("Bet must be > 0.");
  if (!can_wager($bet)) throw new Exception("Not enough credits.");

  bj_reset_round();
  $bj = &$_SESSION['casino_state']['bj'];
  $bj['phase'] = 'player';
  $bj['bet'] = $bet;

  adjust_credits(-$bet);

  $bj['hands'] = [[
    shoe_draw($_SESSION['casino_state']['shoe']),
    shoe_draw($_SESSION['casino_state']['shoe'])
  ]];

  $bj['dealer'] = [
    shoe_draw($_SESSION['casino_state']['shoe']),
    shoe_draw($_SESSION['casino_state']['shoe'])
  ];

  $playerCards = array_values(array_filter($bj['hands'][0], fn($x)=>is_array($x) && isset($x['r'])));
  $dealerCards = array_values(array_filter($bj['dealer'], fn($x)=>is_array($x) && isset($x['r'])));

  $pBJ = bj_is_blackjack($playerCards);
  $dBJ = bj_is_blackjack($dealerCards);

  if ($pBJ || $dBJ) {
    $bj['phase'] = 'complete';
    if ($pBJ && $dBJ) {
      adjust_credits($bet);
      $bj['result'] = ['label'=>'Push (both blackjack)'];
    } elseif ($pBJ) {
      $pay = (int)round($bet * 2.5);
      adjust_credits($pay);
      $bj['result'] = ['label'=>'Blackjack! You win (3:2)'];
    } else {
      $bj['result'] = ['label'=>'Dealer blackjack. You lose'];
    }
    bj_record($bj['result']['label']);
  }
}

function bj_hit(): void {
  $bj = &$_SESSION['casino_state']['bj'];
  if (($bj['phase'] ?? '') !== 'player') return;

  $i = (int)($bj['activeHand'] ?? 0);
  if (!isset($bj['hands'][$i]) || !is_array($bj['hands'][$i])) return;

  $bj['hands'][$i][] = shoe_draw($_SESSION['casino_state']['shoe']);

  $clean = array_values(array_filter($bj['hands'][$i], fn($x)=>is_array($x) && isset($x['r'])));
  $t = bj_hand_value($clean)['total'];

  if ($t > 21) {
    // If split and first hand bust, move to second
    if (count($bj['hands']) > 1 && $i === 0) {
      $bj['activeHand'] = 1;
    } else {
      $bj['phase'] = 'dealer';
      bj_dealer_play();
      $bj['phase'] = 'complete';
      bj_resolve();
      bj_record($bj['result']['label'] ?? 'Round complete');
    }
  }
}

function bj_stand(): void {
  $bj = &$_SESSION['casino_state']['bj'];
  if (($bj['phase'] ?? '') !== 'player') return;

  $i = (int)($bj['activeHand'] ?? 0);

  if (count($bj['hands']) > 1 && $i === 0) {
    $bj['activeHand'] = 1;
    return;
  }

  $bj['phase'] = 'dealer';
  bj_dealer_play();
  $bj['phase'] = 'complete';
  bj_resolve();
  bj_record($bj['result']['label'] ?? 'Round complete');
}

function bj_double(): void {
  $bj = &$_SESSION['casino_state']['bj'];
  if (($bj['phase'] ?? '') !== 'player') return;

  $bet = (int)($bj['bet'] ?? 0);
  if (!can_wager($bet)) throw new Exception("Not enough credits to double.");

  $i = (int)($bj['activeHand'] ?? 0);
  $hand = $bj['hands'][$i] ?? null;
  if (!is_array($hand)) throw new Exception("Invalid hand.");

  $clean = array_values(array_filter($hand, fn($x)=>is_array($x) && isset($x['r'])));
  if (count($clean) !== 2) throw new Exception("Double only allowed on first two cards.");

  adjust_credits(-$bet);

  // Store meta safely as array key
  $bj['hands'][$i]['__meta'] = ['double'=>true];

  $bj['hands'][$i][] = shoe_draw($_SESSION['casino_state']['shoe']);

  // If split, move to next hand; otherwise resolve
  if (count($bj['hands']) > 1 && $i === 0) {
    $bj['activeHand'] = 1;
    return;
  }

  bj_stand();
}

function bj_split(): void {
  $bj = &$_SESSION['casino_state']['bj'];
  if (($bj['phase'] ?? '') !== 'player') return;

  $bet = (int)($bj['bet'] ?? 0);
  if (!can_wager($bet)) throw new Exception("Not enough credits to split.");
  if (count($bj['hands']) !== 1) throw new Exception("Split only allowed once.");

  $hand = $bj['hands'][0] ?? null;
  if (!is_array($hand)) throw new Exception("Invalid hand.");

  $clean = array_values(array_filter($hand, fn($x)=>is_array($x) && isset($x['r'])));
  if (!bj_can_split($clean)) throw new Exception("Split requires a pair.");

  adjust_credits(-$bet);

  $c1 = $clean[0];
  $c2 = $clean[1];

  $bj['hands'] = [
    [ $c1, shoe_draw($_SESSION['casino_state']['shoe']) ],
    [ $c2, shoe_draw($_SESSION['casino_state']['shoe']) ],
  ];

  $bj['activeHand'] = 0;
}

function bj_insurance(int $amt): void {
  $bj = &$_SESSION['casino_state']['bj'];
  if (($bj['phase'] ?? '') !== 'player') return;

  $dealerUp = $bj['dealer'][0] ?? null;
  if (!is_array($dealerUp) || (($dealerUp['r'] ?? '') !== 'A')) {
    throw new Exception("Insurance only when dealer shows Ace.");
  }
  if ((int)($bj['insuranceBet'] ?? 0) > 0) throw new Exception("Insurance already placed.");

  $bet = (int)($bj['bet'] ?? 0);
  $max = (int)floor($bet / 2);
  if ($amt <= 0 || $amt > $max) throw new Exception("Insurance must be 1 to {$max}.");
  if (!can_wager($amt)) throw new Exception("Not enough credits for insurance.");

  adjust_credits(-$amt);
  $bj['insuranceBet'] = $amt;
}

/* ============================================================
   BACCARAT ENGINE (Casino-like, with side bets)
   - Always uses 8 decks (separate shoe in session: shoe8)
   - Bets:
     player, banker, tie
     playerPair, bankerPair (11:1)
     perfectPair (25:1) suited pair either side
============================================================ */

function bac_card_value(array $c): int {
  $r = (string)($c['r'] ?? '');
  if ($r === 'A') return 1;
  if (in_array($r, ['10','J','Q','K'], true)) return 0;
  return (int)$r;
}

function bac_total(array $hand): int {
  $sum = 0;
  foreach ($hand as $c) $sum += bac_card_value($c);
  return $sum % 10;
}

function bac_get_shoe8(): array {
  if (!isset($_SESSION['casino_state']['bac']['shoe8']) || !is_array($_SESSION['casino_state']['bac']['shoe8'])) {
    $_SESSION['casino_state']['bac']['shoe8'] = shoe_init(8);
  } else {
    // Ensure shoe8 stays at 8 decks if something altered it
    $_SESSION['casino_state']['bac']['shoe8']['decks'] = 8;
  }
  return $_SESSION['casino_state']['bac']['shoe8'];
}

function bac_draw(): array {
  $shoe = &$_SESSION['casino_state']['bac']['shoe8'];
  if (!isset($shoe) || !is_array($shoe)) $shoe = shoe_init(8);
  $shoe['decks'] = 8;
  return shoe_draw($shoe);
}

function bac_reset_round(): void {
  $prevHist = $_SESSION['casino_state']['bac']['history'] ?? [];
  $shoe8 = $_SESSION['casino_state']['bac']['shoe8'] ?? null;

  $_SESSION['casino_state']['bac'] = [
    'phase'=>'idle',
    'bet'=>[
      'player'=>0,'banker'=>0,'tie'=>0,
      'playerPair'=>0,'bankerPair'=>0,'perfectPair'=>0,
    ],
    'player'=>[],
    'banker'=>[],
    'result'=>null,
    'history'=>$prevHist,
    'shoe8'=>$shoe8,
  ];

  // ensure shoe exists
  bac_get_shoe8();
}

function bac_snapshot(): array {
  $b = $_SESSION['casino_state']['bac'];
  $p = $b['player'] ?? [];
  $bk = $b['banker'] ?? [];

  return [
    'phase'=>(string)($b['phase'] ?? 'idle'),
    'bet'=>$b['bet'] ?? [],
    'player'=>$p,
    'banker'=>$bk,
    'playerTotal'=>bac_total(array_values(array_filter($p, fn($x)=>is_array($x) && isset($x['r'])))),
    'bankerTotal'=>bac_total(array_values(array_filter($bk, fn($x)=>is_array($x) && isset($x['r'])))),
    'result'=>$b['result'],
    'history'=>$b['history'] ?? [],
    'shoe'=>[
      'decks'=>8,
      'totalCards'=> (int)($_SESSION['casino_state']['bac']['shoe8']['total'] ?? 0),
      'drawn'=> (int)($_SESSION['casino_state']['bac']['shoe8']['drawn'] ?? 0),
      'remaining'=> is_array($_SESSION['casino_state']['bac']['shoe8']['cards'] ?? null) ? count($_SESSION['casino_state']['bac']['shoe8']['cards']) : 0,
      'shuffles'=> (int)($_SESSION['casino_state']['bac']['shoe8']['shuffles'] ?? 1),
      'lastShuffle'=> (string)($_SESSION['casino_state']['bac']['shoe8']['lastShuffle'] ?? ''),
    ],
  ];
}

function bac_record(string $label, string $outcome): void {
  $b = &$_SESSION['casino_state']['bac'];
  array_unshift($b['history'], [
    'time'=>nowIso2(),
    'label'=>$label,
    'outcome'=>$outcome,
    'bet'=>$b['bet'],
    'player'=>$b['player'],
    'banker'=>$b['banker'],
    'playerTotal'=>bac_total($b['player']),
    'bankerTotal'=>bac_total($b['banker']),
  ]);
  $b['history'] = array_slice($b['history'], 0, BAC_MAX_HISTORY);
}

/**
 * Side bet helpers
 */
function bac_is_pair(array $hand): bool {
  if (count($hand) < 2) return false;
  return (string)$hand[0]['r'] === (string)$hand[1]['r'];
}

function bac_is_perfect_pair(array $hand): bool {
  if (count($hand) < 2) return false;
  return ((string)$hand[0]['r'] === (string)$hand[1]['r']) && ((string)$hand[0]['s'] === (string)$hand[1]['s']);
}

/**
 * Baccarat deal with side bets
 * Bets supported:
 * - player, banker, tie
 * - playerPair, bankerPair (11:1)
 * - perfectPair (25:1) suited pair either side
 */
function bac_deal(array $bet): void {
  bac_get_shoe8();

  $p  = (int)($bet['player'] ?? 0);
  $bk = (int)($bet['banker'] ?? 0);
  $t  = (int)($bet['tie'] ?? 0);

  $pp = (int)($bet['playerPair'] ?? 0);
  $bp = (int)($bet['bankerPair'] ?? 0);
  $pf = (int)($bet['perfectPair'] ?? 0);

  foreach ([$p,$bk,$t,$pp,$bp,$pf] as $x) {
    if ($x < 0) throw new Exception("Invalid bet.");
  }

  $sum = $p + $bk + $t + $pp + $bp + $pf;
  if ($sum <= 0) throw new Exception("Place at least one bet > 0.");
  if (!can_wager($sum)) throw new Exception("Not enough credits.");

  bac_reset_round();
  $b = &$_SESSION['casino_state']['bac'];

  $b['phase'] = 'complete';
  $b['bet'] = [
    'player'=>$p,'banker'=>$bk,'tie'=>$t,
    'playerPair'=>$pp,'bankerPair'=>$bp,'perfectPair'=>$pf,
  ];

  adjust_credits(-$sum);

  // Initial deal: 2 cards each
  $b['player'] = [ bac_draw(), bac_draw() ];
  $b['banker'] = [ bac_draw(), bac_draw() ];

  $pTotal = bac_total($b['player']);
  $bkTotal = bac_total($b['banker']);
  $natural = ($pTotal >= 8 || $bkTotal >= 8);

  $playerThird = null;

  // Third card rules if not natural
  if (!$natural) {
    // Player draws
    if ($pTotal <= 5) {
      $playerThird = bac_draw();
      $b['player'][] = $playerThird;
      $pTotal = bac_total($b['player']);
    }

    $bkTotal = bac_total($b['banker']);

    // Banker draws based on rules
    if ($playerThird === null) {
      if ($bkTotal <= 5) $b['banker'][] = bac_draw();
    } else {
      $pt = bac_card_value($playerThird);

      if ($bkTotal <= 2) $b['banker'][] = bac_draw();
      elseif ($bkTotal === 3 && $pt !== 8) $b['banker'][] = bac_draw();
      elseif ($bkTotal === 4 && $pt >= 2 && $pt <= 7) $b['banker'][] = bac_draw();
      elseif ($bkTotal === 5 && $pt >= 4 && $pt <= 7) $b['banker'][] = bac_draw();
      elseif ($bkTotal === 6 && ($pt === 6 || $pt === 7)) $b['banker'][] = bac_draw();
      // 7 stands
    }
  }

  // Final totals
  $pTotal = bac_total($b['player']);
  $bkTotal = bac_total($b['banker']);

  $out = 'tie';
  $label = 'Tie';
  if ($pTotal > $bkTotal) { $out = 'player'; $label = 'Player wins'; }
  elseif ($pTotal < $bkTotal) { $out = 'banker'; $label = 'Banker wins'; }

  // Side bet resolution (based on first two cards only)
  $playerPairHit = bac_is_pair($b['player']);
  $bankerPairHit = bac_is_pair($b['banker']);
  $perfectPairHit = bac_is_perfect_pair($b['player']) || bac_is_perfect_pair($b['banker']);

  $payoutParts = [];

  // MAIN BETS
  if ($out === 'player') {
    if ($p > 0) { $pay = $p * 2; adjust_credits($pay); $payoutParts[] = "Player +".($pay-$p); }
    if ($bk > 0) $payoutParts[] = "Banker lost -{$bk}";
    if ($t > 0) $payoutParts[] = "Tie lost -{$t}";
  } elseif ($out === 'banker') {
    if ($bk > 0) {
      $profit = (int)floor($bk * 0.95); // 5% commission
      $pay = $bk + $profit;
      adjust_credits($pay);
      $payoutParts[] = "Banker +{$profit} (5% comm)";
    }
    if ($p > 0) $payoutParts[] = "Player lost -{$p}";
    if ($t > 0) $payoutParts[] = "Tie lost -{$t}";
  } else {
    // Tie
    if ($t > 0) { $pay = $t * 9; adjust_credits($pay); $payoutParts[] = "Tie +".($pay-$t); }
    if ($p > 0) { adjust_credits($p); $payoutParts[] = "Player push"; }
    if ($bk > 0) { adjust_credits($bk); $payoutParts[] = "Banker push"; }
  }

  // SIDE BETS
  // Pair pays 11:1 profit => total return 12x
  if ($pp > 0) {
    if ($playerPairHit) { $pay = $pp * 12; adjust_credits($pay); $payoutParts[] = "Player Pair +".($pay-$pp); }
    else $payoutParts[] = "Player Pair lost -{$pp}";
  }
  if ($bp > 0) {
    if ($bankerPairHit) { $pay = $bp * 12; adjust_credits($pay); $payoutParts[] = "Banker Pair +".($pay-$bp); }
    else $payoutParts[] = "Banker Pair lost -{$bp}";
  }
  // Perfect pair pays 25:1 profit => total return 26x
  if ($pf > 0) {
    if ($perfectPairHit) { $pay = $pf * 26; adjust_credits($pay); $payoutParts[] = "Perfect Pair +".($pay-$pf); }
    else $payoutParts[] = "Perfect Pair lost -{$pf}";
  }

  $b['result'] = [
    'outcome'=>$out,
    'label'=>$label,
    'natural'=>$natural,
    'pTotal'=>$pTotal,
    'bkTotal'=>$bkTotal,
    'side'=>[
      'playerPair'=>$playerPairHit,
      'bankerPair'=>$bankerPairHit,
      'perfectPair'=>$perfectPairHit,
    ],
    'payoutLabel'=>implode(' • ', $payoutParts),
  ];

  bac_record($label." • ".$b['result']['payoutLabel'], $out);
}
