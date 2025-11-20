#!/usr/bin/env python
"""
Compatibility wrapper for legacy misspelled filename.
This file forwards exec to the correctly named script
`digitalstreaming_shift_make.py` so older callers still work.
"""
import os
import sys

script_dir = os.path.dirname(os.path.abspath(__file__))
target = os.path.join(script_dir, 'digitalstreaming_shift_make.py')
if not os.path.exists(target):
    sys.stderr.write('Error: target script not found: ' + target + "\n")
    sys.exit(1)

# Replace current process with correct script, preserving args
os.execv(sys.executable, [sys.executable, target] + sys.argv[1:])


# Q: 禁止シフト1

# F勤->ハ勤禁止


# ## 数理モデル

# In[14]:


problem = pulp.LpProblem(sense=pulp.LpMinimize)


# ## 変数

# In[15]:


# x[m, d, c]: 職員 m が d 日の勤務 c であるかどうか

x = pulp.LpVariable.dicts(
    "x", [(m, d, c) for m in M for d in D for c in C], cat="Binary"
)


# In[16]:


# その日の勤務はシフトのうち, いずれか1つ

for m in M:
    for d in D:
        problem += pulp.lpSum([x[m, d, c] for c in C]) == 1


# In[17]:


# y[m, d]:職員 m が d 日から連勤かどうか

y = pulp.LpVariable.dicts(
    "y", [(m, d) for m in M for d in range(1, last_day_as_int)], cat="Binary"
)


# ## 制約式

# ## デジタル報道配信班

# In[18]:


# 制約(0-1)　<- 相性悪そう
# 各日の各シフトにおいて、2人以上が勤務しなければならない

for d in D:
    for c in C[2:]:
        problem += pulp.lpSum([x[m, d, c] for m in M]) >= 2


# In[19]:


# 制約(0-1)
# 各職員の希望休みの日は休みにする

for i in range(0, len(M)):
    for d in desired_vacation[i]:
        problem += x[M[i], d, C[0]] + x[M[i], d, C[1]] == 1


# In[20]:


# 制約(0-2)
# クレカ担当は1, 5, 10

credit_days = [1, 5, 10]

for d in credit_days:
    problem += x[M[7], d, C[2]] == 1


# In[21]:


# 制約(0-3.1)
# 平日、土日祝ともハ勤、F勤に副部長1人は必ず入る。ただし、各副部長がどちらかに偏らないように配慮<-現状, バランスよくF勤を割り当てるような配慮はできていない
# ハ勤

for d in D:
    problem += pulp.lpSum([x[m, d, C[2]] for m in M[1:5]]) >= 1


# In[22]:


# 制約(0-3.2)
# 平日、土日祝ともハ勤、F勤に副部長1人は必ず入る。ただし、各副部長がどちらかに偏らないように配慮 <-現状, バランスよくF勤を割り当てるような配慮はできていない
# F勤

for d in D:
    problem += pulp.lpSum([x[m, d, C[4]] for m in M[1:5]]) >= 1


# In[23]:


print(M[1:5])


# In[24]:


# 制約(0-4.1)
# 部員AとBは、平日はト勤またはF勤、土日祝はハ勤またはF勤
# 平日

problem += pulp.lpSum([x[m, d, C[2]] for m in M[5:7] for d in holi_days]) == 0


# In[25]:


# 制約(0-4.2)
# 部員AとBは、平日はト勤またはF勤、土日祝はハ勤またはF勤
# 土日祝

problem += pulp.lpSum([x[m, d, C[3]] for m in M[5:7] for d in holi_days]) == 0

# 制約(0-4.3)
# 臨時・派遣Cはハ勤のみ(業務範囲の観点より)

problem += pulp.lpSum([x[M[10], d, c] for c in C[3:] for d in D]) == 0

# In[26]:


# 制約(1-1)
# 公休を, 計6回確保する

for m in M:
    problem += pulp.lpSum([x[m, d, C[0]] for d in D]) == 6


# In[27]:


# 制約(1-2.1)
# 特殊級を, 計3回以上確保する

for m in M:
    problem += pulp.lpSum([x[m, d, C[1]] for d in D]) >= 3


# In[28]:


# 制約(1-2.2)
# 特殊級を, 計5回までに制限

for m in M:
    problem += pulp.lpSum([x[m, d, C[1]] for d in D]) <= 5


# In[29]:


# 制約(2-1)
# 連続勤務は4日までしか許されない

for m in M:
    for d in D[4:]:
        problem += (
            pulp.lpSum([x[m, d - h, c] for h in range(4 + 1) for c in C[2:]]) <= 4
        )


# In[30]:


# 制約(2-2)
# F勤の連続勤務は2日までしか許されない

for m in M:
    for d in D[1:]:
        problem += pulp.lpSum([x[m, d - 1, C[4]] + x[m, d, C[4]]]) <= 2


# In[31]:


# 制約(2-3)
# F勤->ハ勤は禁止

for m in M:
    for d in D[2:]:
        problem += pulp.lpSum([x[m, d - 1, C[4]] + x[m, d, C[2]]]) <= 1


# In[32]:


# 制約(3) <- かなり, 相性が悪そう, 希望の休みと休みの制約のみでも最適解を見つけられていない
# 連勤希望の人はできるだけ連勤になるように設定
# 連休を2回以上設定

# 連休を希望しているか否か
# consecutive_holidays = [1, 1, 1, 0, 1, 1, 0, 0, 1, 1, 1]

# for i in range(0, len(M)):
#     if consecutive_holidays[i] == 1:
#         for d in D[:-1]:
#             problem += x[M[i], d, C[0]] + x[M[i], d, C[1]] + x[M[i], d+1, C[0]] + x[M[i], d+1, C[1]] - y[M[i], d] <= 1
#             problem += x[M[i], d, C[0]] + x[M[i], d, C[1]] + x[M[i], d+1, C[0]] + x[M[i], d+1, C[1]] - y[M[i], d] * 2 >= 0
#     else:
#         for d in D[:-1]:
#             problem += y[M[i], d] == 2

#     problem += pulp.lpSum([y[m, d] for d in D[:-1]]) >= 2


# In[33]:


pulp.LpStatus[problem.solve()]


# In[34]:


# print(pulp.LpStatus[problem.solve()])


# In[35]:


# for p in M:
#     buf = []
#     for d in D:
#         for c in C:
#             if x[p, d, c].value():
#                 buf.append(f" {c}")


# In[36]:


# print(
#     " 社員名, 1, 2, 3, 4, 5, 6, 7, 8, 9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30, 公休, 特殊級, ハ勤, ト勤, F勤"
# )
# for p in M:
#     buf = []
#     for d in D:
#         for c in C:
#             if x[p, d, c].value():
#                 buf.append(f" {c}")
#     print(
#         f"{p},{','.join(buf)},{buf.count(' 公休'): 4d},{buf.count(' 特殊休'): 4d}, {buf.count(' ハ'): 4d}, {buf.count(' ト'): 4d}, {buf.count(' F'): 4d}"
#     )
# for c in C[0:]:
#     buf = []
#     for d in D:
#         buf.append(f" {str(int(sum([x[p, d, c].value() for p in M])))}")
#     print(f"{c}:{','.join(buf)}")


# In[ ]:

from datetime import datetime


df = pd.DataFrame(index=D, columns=M)

for d in D:
    for m in M:
        filtered_list = [c for c in C if value(x[m, d, c]) == 1]
        if filtered_list:  # リストが空でない場合
            df.loc[d, m] = filtered_list[0]
        else:
            df.loc[d, m] = "条件に一致する要素は見つかりませんでした"

# 各列ごとに"公休"などの数を数えて新しい行を追加
for c in C:
    count_public_holiday = df.apply(lambda x: x[x == c].count())
    df.loc[c] = count_public_holiday
# 行ごとに"公休"の数を数えて新しい列を追加
for c in C:
    df[c] = df.apply(lambda row: row[row == c].count(), axis=1)

# 転置して出力
# UTCで現在時刻を取得
current_datetime_utc = datetime.utcnow()
# 日本のタイムゾーンを定義
japan_timezone = pytz.timezone("Asia/Tokyo")
# UTCから日本時間に変換
current_datetime_jst = current_datetime_utc.astimezone(japan_timezone)
# 指定のフォーマットで日時を文字列に変換
formatted_datetime = current_datetime_jst.strftime("%Y%m%d_%H%M%S")
output_filename = f"digi_st_result_year{year}_month{month}_{formatted_datetime}.csv"
#表示用
df.T.to_csv("../data/" + output_filename, encoding="utf-8")
#ダウンロード用
df.T.to_csv("../data/dl/" + output_filename, encoding="shift-jis")

f = open("searchpath.txt", "w")
f.write(output_filename)
f.close()
