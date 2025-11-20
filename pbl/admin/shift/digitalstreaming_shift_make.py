#!/usr/bin/env python
# coding: utf-8

from pulp import *
import pytz
import sys
sys.stdout.reconfigure(encoding='utf-8')

import pulp
import pandas as pd

# # パラメータ

# ### 職員

M = []

# 年と月を指定
import calendar
import argparse
import sys

year = int(sys.argv[2])
month = int(sys.argv[3])
# 実行モード: 第4引数に 'relaxed' があれば緩和モードで動作
is_relaxed = False
if len(sys.argv) >= 5 and (sys.argv[4] == 'relaxed' or sys.argv[4] == '--relaxed'):
    is_relaxed = True

# 指定した月のカレンダーを作成し、月の最終日を得る
last_day = calendar.monthrange(year, month)[1]
last_day_as_int = int(last_day)
D = list(range(1, last_day_as_int + 1))

# 営業日, 土日祝
import jpbizday
import datetime
import calendar


def get_business_days(year, month):
    business_days = []
    current_date = datetime.date(year, month, 1)
    last_day = calendar.monthrange(year, month)[1]
    for _ in range(current_date.day, last_day + 1):
        if jpbizday.is_bizday(current_date):
            business_days.append(current_date.day)
        current_date += datetime.timedelta(days=1)
    return business_days

business_days = get_business_days(year, month)
print(business_days)


def get_holi_days(year, month):
    business_days = get_business_days(year, month)
    holi_days = sorted(list(set(D) - set(business_days)))
    return holi_days

holi_days = get_holi_days(year, month)
print(holi_days)

# C: シフトの集合
# シフト集合: 公休/特殊休 と 各勤務時間帯を含める
C = ["公休", "特殊休", "ロ", "J", "L", "ホ", "A", "H", "ハ", "ト", "F"]

# 休み希望
import csv
import os

# 引数を取得
filename = sys.argv[1]
# filename = 'digitalstreaming_desired_vacation_pre.csv'
script_dir = os.path.dirname(os.path.abspath(__file__))
data_path = os.path.join(script_dir, "../data", os.path.basename(filename))

# 読み取り: req CSV から従業員リストと希望休を読み取る
desired_vacation = {}
M = []
with open(data_path, "r", encoding='utf-8') as f:
    reader = csv.reader(f)
    header = next(reader, None)
    csv_M = []
    for row in reader:
        name = row[0].strip()
        if name == '':
            continue
        csv_M.append(name)
        raw = row[1] if len(row) > 1 else ''
        cleaned = [v.strip() for v in raw.strip("[] ").split(",") if v.strip()]
        desired_vacation[name] = [int(x) for x in cleaned] if cleaned else []

# ユーザー指定の従業員リストに固定（試行用）
# 指示: 部長A, 部長B, 副部長A, 副部長B, 部員A, 部員B, 部員C,
#       派遣A, 派遣B, 派遣C, 派遣D, 派遣E, 副部長C, 副部長D, 部員D, 派遣F, 派遣G
M = [
    '部長A', '部長B', '副部長A', '副部長B', '部員A', '部員B', '部員C',
    '派遣A', '派遣B', '派遣C', '派遣D', '派遣E', '副部長C', '副部長D', '部員D',
    '派遣F', '派遣G'
]

# CSV に存在した名前の希望休データは取り込み済み。指定リストに存在しない場合は空リストにする
for name in M:
    if name not in desired_vacation:
        desired_vacation[name] = []

# 数理モデル
problem = pulp.LpProblem(sense=pulp.LpMinimize)

# 変数
x = pulp.LpVariable.dicts(
    "x", [(m, d, c) for m in M for d in D for c in C], cat="Binary"
)

for m in M:
    for d in D:
        # 1 日につき 1 シフトを割り当てる
        problem += pulp.lpSum([x[m, d, c] for c in C]) == 1

# 補助変数: 連休開始など（後で使用）
y = pulp.LpVariable.dicts(
    "y", [(m, d) for m in M for d in range(1, last_day_as_int)], cat="Binary"
)

# 既存ルールを踏まえつつ、各時間帯の最低人数は設定可能
for d in D:
    for c in [t for t in C if t not in ("公休", "特殊休")]:
        # 最低人数: デフォルト 0、将来的に設定可能（ここでは >=0 のまま）
        pass

# 希望休は必ず尊重（公休または特殊休のいずれか）
# 希望休は原則尊重する
# 二段階法: 通常はハード制約として追加（初回）し、緩和モードではスラック変数を導入して再解を行う
desire_slack = {}
if not is_relaxed:
    for m in M:
        for d in desired_vacation.get(m, []):
            # 初回はハード制約: 必ず公休または特殊休
            problem += x[m, d, "公休"] + x[m, d, "特殊休"] == 1
else:
    for m in M:
        for d in desired_vacation.get(m, []):
            # バイナリスラック: 0 = 希望を満たす, 1 = 希望を破る
            desire_slack[(m, d)] = pulp.LpVariable(f"desire_slack_{m}_{d}", lowBound=0, upBound=1, cat='Binary')
            # スラックを許容した等式
            problem += x[m, d, "公休"] + x[m, d, "特殊休"] + desire_slack[(m, d)] == 1

# （例）クレカ担当等の固定割当があればここに追加（現状はなし）

# 副部長等の追加制約は必要に応じてここへ追加

# 公休/特殊休 の合計は月ごとに最低 (MIN_OFF) 日（ハード制約）
MIN_OFF = 8  # 既定値: 8 日。必要に応じて 9 に戻す。
for m in M:
    problem += pulp.lpSum([x[m, d, "公休"] + x[m, d, "特殊休"] for d in D]) >= MIN_OFF

# 連続勤務制約: 最大 5 日連続出勤（すなわち任意の6日区間で出勤日数 <=5）
work_types = [t for t in C if t not in ("公休", "特殊休")]
for m in M:
    for i in range(0, len(D) - 5):
        window = D[i : i + 6]
        problem += pulp.lpSum([x[m, d, c] for d in window for c in work_types]) <= 5

# 派遣の抽出（名前に '派遣' を含むものを派遣とする）
haken = [m for m in M if '派遣' in m]
# 派遣ローテの対象は明示的に派遣A..派遣E の5名とする（存在しない場合は無視）
haken_primary_names = ['派遣A', '派遣B', '派遣C', '派遣D', '派遣E']
haken_primary = [m for m in haken_primary_names if m in M]

# 非派遣リスト
non_haken = [m for m in M if m not in haken]

# 派遣のローテーション要件（可能な限り満たす）
weekday_ro = [d for d in D if datetime.date(year, month, d).weekday() < 5 and d in D]
sat_days = [d for d in D if datetime.date(year, month, d).weekday() == 5]
sun_days = [d for d in D if datetime.date(year, month, d).weekday() == 6 or d in holi_days]

# 派遣が3人以上いる場合は厳格に必要人数を満たす（過剰配置を避けるため等号を使う）
if len(haken_primary) >= 3:
    # 平日: ロ,J,L 各1人（過剰配置を避けるため等号）
    for d in weekday_ro:
        # 各勤務帯につき最低1名確保（過剰配置は許容）。等号だと過剰に厳しくなるため緩和。
        problem += pulp.lpSum([x[m, d, 'ロ'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'J'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'L'] for m in haken_primary]) >= 1
    # 土曜: ホ1, J1, L1（過剰配置を避ける）
    for d in sat_days:
        problem += pulp.lpSum([x[m, d, 'ホ'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'J'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'L'] for m in haken_primary]) >= 1
    # 日祝: A1,H1,L1
    for d in sun_days:
        problem += pulp.lpSum([x[m, d, 'A'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'H'] for m in haken_primary]) >= 1
        problem += pulp.lpSum([x[m, d, 'L'] for m in haken_primary]) >= 1

# H,J,L 勤の翌日はロ/ハ勤を禁止（派遣トップ5 に対して適用）
if len(haken_primary) > 0:
    for m in haken_primary:
        for d in D[:-1]:
            for typ in ['H', 'J', 'L']:
                problem += x[m, d, typ] + x[m, d + 1, 'ロ'] <= 1
                problem += x[m, d, typ] + x[m, d + 1, 'ハ'] <= 1

# 派遣ローテ外（haken に含まれるが haken_primary でない）の簡易配置 (ソフト制約):
# 希望休が無ければ平日は 'ロ', 休日は '公休' を優先するが、必要に応じて違反可能にする。
other_haken = [m for m in haken if m not in haken_primary]
# 違反を許すためのバイナリスラック変数を定義
other_vio = pulp.LpVariable.dicts('othervio', [(m, d) for m in other_haken for d in D], cat='Binary')
for m in other_haken:
    for d in D:
        if d in desired_vacation.get(m, []):
            # 希望休は既に上で強制しているため何もしない
            continue
        if d in weekday_ro:
            # 平日は原則ロ (x==1) を期待するが、othervio を 1 にすれば破ることを許す
            problem += x[m, d, 'ロ'] + other_vio[m, d] >= 1
        elif d in sat_days or d in sun_days:
            # 週末/祝日は原則公休
            problem += x[m, d, '公休'] + other_vio[m, d] >= 1

# ペナルティ: ローテ外派遣のデフォルト違反は小さめのコストで罰する（希望休よりは小さい）
pen_other_vio = 2
other_vio_term = pulp.lpSum([other_vio[k] for k in other_vio])
# この項は後で目的関数に加えるため、obj に追加する（下の obj 作成箇所で使用）

# 全員月一回は2日以上連休（2連休）をとる
z = pulp.LpVariable.dicts('z', [(m, s) for m in M for s in range(1, last_day_as_int)], cat='Binary')
off = pulp.LpVariable.dicts('off', [(m, d) for m in M for d in D], cat='Binary')
for m in M:
    for d in D:
        # off ==1 なら公休または特殊休のいずれか
        problem += off[m, d] >= x[m, d, '公休']
        problem += off[m, d] >= x[m, d, '特殊休']
        problem += off[m, d] <= x[m, d, '公休'] + x[m, d, '特殊休']
    # z により 2 連休の開始を表す
    problem += pulp.lpSum([z[m, s] for s in range(1, last_day_as_int)]) >= 1
    for s in range(1, last_day_as_int):
        problem += z[m, s] <= off[m, s]
        problem += z[m, s] <= off[m, s + 1]

# ----- 派遣トップ5 の週ごとの休日数の平準化（差を小さくする） -----
# 週は1日〜7日、8日〜14日、... の固定ブロックで分割します（簡易扱い）
weeks = []
num_weeks = (last_day_as_int + 6) // 7
for w in range(num_weeks):
    start = w * 7 + 1
    end = min(last_day_as_int, (w + 1) * 7)
    weeks.append(list(range(start, end + 1)))

# 派遣トップ5 の各週ごとの off 日数（公休 or 特殊休 の合計）を表す補助式は off 変数の和で表現
# 差分の絶対値を表すための補助変数を作成（各週ごとに派遣間の差分を最小化）
diff_pos = {}
diff_neg = {}
pair_list = []
for i in range(len(haken_primary)):
    for j in range(i + 1, len(haken_primary)):
        pair_list.append((haken_primary[i], haken_primary[j]))

for (i_name, j_name) in pair_list:
    for w_idx in range(len(weeks)):
        # 変数名に日本語が含まれるとソルバの.lp入出力で問題になる場合があるため
        # ここでは英数字のみの名前を使って変数を作成する
        safe_i = i_name.replace('派遣', 'h').replace('副部長','v').replace('部長','b').replace('部員','w')
        safe_j = j_name.replace('派遣', 'h').replace('副部長','v').replace('部長','b').replace('部員','w')
        vname_pos = f"dpos_{safe_i}_{safe_j}_w{w_idx}"
        vname_neg = f"dneg_{safe_i}_{safe_j}_w{w_idx}"
        diff_pos[(i_name, j_name, w_idx)] = pulp.LpVariable(vname_pos, lowBound=0, cat='Integer')
        diff_neg[(i_name, j_name, w_idx)] = pulp.LpVariable(vname_neg, lowBound=0, cat='Integer')
        # 制約: off_iw - off_jw == diff_pos - diff_neg
        off_iw = pulp.lpSum([off[i_name, d] for d in weeks[w_idx]])
        off_jw = pulp.lpSum([off[j_name, d] for d in weeks[w_idx]])
        problem += off_iw - off_jw == diff_pos[(i_name, j_name, w_idx)] - diff_neg[(i_name, j_name, w_idx)]

# 目的関数に平準化ペナルティを追加するため、後で obj に加える項を用意
# 平準化は最優先ではないため無効化（まずは平準化項を外して可行性を確認）
pen_balance = 0
balance_term = pulp.lpSum([diff_pos[k] + diff_neg[k] for k in diff_pos])

# 非派遣の週末出勤はできるだけ避ける（目的関数でペナルティ）
# 非派遣は休日以外は原則「ホ」で埋める（ただし公休/特殊休はILPで配分可能）
# ここでは非派遣に対して、ホ以外の勤務種別割当を禁止する制約を付与する
for m in non_haken:
    for d in D:
        for c in C:
            if c not in ("公休", "特殊休", "ホ"):
                problem += x[m, d, c] == 0

# 非派遣の週末出勤はできるだけ避ける（目的関数でペナルティ）

# 目的関数: ペナルティの定義
# 1) 非派遣の週末出勤ペナルティ
weekend_days = sorted(list(set(sat_days + sun_days + holi_days)))
pen_weekend = 10
term_weekend = pulp.lpSum([x[m, d, c] for m in non_haken for d in weekend_days for c in work_types])

# 2) 公休/特殊休の目標比率ズレ (公休目標6, 特殊休目標3)
pub_plus = pulp.LpVariable.dicts('pub_plus', M, lowBound=0, cat='Integer')
pub_minus = pulp.LpVariable.dicts('pub_minus', M, lowBound=0, cat='Integer')
sp_plus = pulp.LpVariable.dicts('sp_plus', M, lowBound=0, cat='Integer')
sp_minus = pulp.LpVariable.dicts('sp_minus', M, lowBound=0, cat='Integer')
for m in M:
    pub_count = pulp.lpSum([x[m, d, '公休'] for d in D])
    sp_count = pulp.lpSum([x[m, d, '特殊休'] for d in D])
    problem += pub_count - 6 == pub_plus[m] - pub_minus[m]
    problem += sp_count - 3 == sp_plus[m] - sp_minus[m]

# 総合目的関数の組み立て（派遣の週ごとの休日平準化ペナルティを追加）
# 希望休スラックに対するペナルティは変数にしておき、必要なら下げて再解を試みる
desire_penalty_initial = 10000  # 初回想定（使用しないが定義）
desire_penalty_relaxed = 10     # 緩和時のペナルティ
obj_terms = [pen_weekend * term_weekend, pulp.lpSum([pub_plus[m] + pub_minus[m] + sp_plus[m] + sp_minus[m] for m in M]), pen_balance * balance_term, pen_other_vio * other_vio_term]
if is_relaxed:
    # 緩和モードでは希望休スラックにペナルティを課す
    desire_terms = [desire_slack[k] for k in desire_slack]
    obj_terms.append(desire_penalty_relaxed * pulp.lpSum(desire_terms))
obj = pulp.lpSum(obj_terms)
problem += obj

status = problem.solve()
print('Solver status code:', status)
print('Solver status:', pulp.LpStatus.get(status, 'Unknown'))
print('Objective value:', pulp.value(problem.objective))

# Debug: 出力値の健全性チェックをログに書く
assign_log = os.path.join(script_dir, 'digitalstreaming_assign_debug.log')
with open(assign_log, 'w', encoding='utf-8') as lf:
    lf.write(f'Solver status: {pulp.LpStatus.get(status, "Unknown")}\n')
    lf.write(f'Objective: {pulp.value(problem.objective)}\n')
    lf.write('m,d,sum_x,assigned\n')
    for m in M:
        for d in D:
            s = 0
            assigned = []
            for c in C:
                try:
                    v = value(x[m, d, c])
                except Exception:
                    v = None
                if v is None:
                    # variable missing? indicate
                    lf.write(f'{m},{d},VAR_MISSING,{c}\n')
                    continue
                # treat near-1 as 1
                if abs(v - 1) < 1e-6:
                    assigned.append(c)
                if v is not None:
                    try:
                        s += float(v)
                    except Exception:
                        pass
            lf.write(f"{m},{d},{s}," + ("|".join(assigned) if assigned else "-") + "\n")
    lf.write('\nEnd of assignment debug\n')

from datetime import datetime


df = pd.DataFrame(index=D, columns=M)
for d in D:
    for m in M:
        filtered_list = [c for c in C if value(x[m, d, c]) == 1]
        if filtered_list:
            df.loc[d, m] = filtered_list[0]
        else:
            df.loc[d, m] = "条件に一致する要素は見つかりませんでした"

for c in C:
    count_public_holiday = df.apply(lambda x: x[x == c].count())
    df.loc[c] = count_public_holiday
for c in C:
    df[c] = df.apply(lambda row: row[row == c].count(), axis=1)

current_datetime_utc = datetime.utcnow()
japan_timezone = pytz.timezone("Asia/Tokyo")
current_datetime_jst = current_datetime_utc.astimezone(japan_timezone)
# formatted timestamp for filenames
formatted_datetime = current_datetime_jst.strftime("%Y%m%d_%H%M%S")
output_filename = f"digi_st_result_year{year}_month{month}_{formatted_datetime}.csv"
# 出力ファイルを書き込み
d_out_path = os.path.join(script_dir, '..', 'data', output_filename)
df.T.to_csv(d_out_path, encoding='utf-8')
df.T.to_csv(os.path.join(script_dir, '..', 'data', 'dl', output_filename), encoding='shift-jis')

# 初回が最適解でなかった（または割当不足が確認された）場合は緩和して再試行する
final_status = pulp.LpStatus.get(status, 'Unknown')
need_relax = False
if final_status != 'Optimal':
    need_relax = True
else:
    # さらに、結果に未割当が含まれていれば緩和を行う
    if (df == "条件に一致する要素は見つかりませんでした").any().any():
        need_relax = True

searchstatus_path = os.path.join(script_dir, 'searchstatus.txt')
if need_relax:
    # 初回（非緩和）モードから来た場合は、ここで別プロセスを起動して緩和モードで再実行する
    if not is_relaxed:
        print('Initial solve failed or incomplete; launching relaxed run as a separate process')
        # 再度このスクリプトを 'relaxed' 引数付きで実行する
        import subprocess
        cmd = [sys.executable, os.path.join(script_dir, os.path.basename(__file__)), os.path.basename(filename), str(year), str(month), 'relaxed']
        # 実行し、出力をそのまま流す
        proc = subprocess.run(cmd, cwd=script_dir)
        # 親は子の終了コードをそのまま返す
        sys.exit(proc.returncode)
    else:
        # すでに緩和モードで実行して失敗した場合は STATUS:FAILED を出力
        with open(searchstatus_path, 'w', encoding='utf-8') as sf:
            sf.write('STATUS:FAILED\n')
            sf.write('MESSAGE:Relaxed solve also failed.\n')
        sys.exit(2)
else:
    # 初回で問題なければ結果を出力する
    if is_relaxed:
        # この実行は緩和モード。元条件で作れなかったことを通知するため STATUS:RELAXED を書く。
        # 破られた希望休の従業員名を列挙する
        violated = []
        for m in M:
            violated_flag = False
            for d in desired_vacation.get(m, []):
                try:
                    assigned = df.loc[d, m]
                except Exception:
                    assigned = None
                if assigned is None or assigned not in ("公休", "特殊休"):
                    violated_flag = True
                    break
            if violated_flag:
                violated.append(m)

        with open(searchstatus_path, 'w', encoding='utf-8') as sf:
            sf.write('STATUS:RELAXED\n')
            sf.write('MESSAGE:Initial hard-constrained solve failed; relaxed solve succeeded.\n')
            sf.write('OUT:' + output_filename + '\n')
            sf.write('VIOLATED_NAMES:' + (','.join(violated) if violated else '') + '\n')

        # searchpath.txt を通常通り更新
        with open(os.path.join(script_dir, 'searchpath.txt'), 'w', encoding='utf-8') as spf:
            spf.write(output_filename)

        sys.exit(0)
    else:
        # 通常モードでの正常終了
        with open(searchstatus_path, 'w', encoding='utf-8') as sf:
            sf.write('STATUS:OK\n')
            sf.write('MESSAGE:Initial solve succeeded.\n')
            sf.write('OUT:' + output_filename + '\n')

        # searchpath.txt を通常通り更新
        with open(os.path.join(script_dir, 'searchpath.txt'), 'w', encoding='utf-8') as spf:
            spf.write(output_filename)

        sys.exit(0)
