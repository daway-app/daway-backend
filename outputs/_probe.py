# -*- coding: utf-8 -*-
def srgb(c):
    c = c/255.0
    return c/12.92 if c <= 0.03928 else ((c+0.055)/1.055)**2.4
def lum(h):
    h = h.lstrip('#')
    return 0.2126*srgb(int(h[0:2],16))+0.7152*srgb(int(h[2:4],16))+0.0722*srgb(int(h[4:6],16))
def r(a,b):
    L1,L2 = lum(a),lum(b)
    if L1<L2: L1,L2=L2,L1
    return (L1+0.05)/(L2+0.05)

print("== candidate replacements (need 4.5 unless noted) ==")
tests = [
 ("#B45309","#FFF7ED","warning-text on orange-50"),
 ("#B45309","#FEF3C7","warning-text on amber-100"),
 ("#B45309","#FEF9C3","warning-text on yellow-100"),
 ("#9A3412","#FFF7ED","orange-800 on orange-50"),
 ("#C2410C","#FFF7ED","orange-700 on orange-50"),
 ("#15803D","#DCFCE7","success-text on green-100"),
 ("#15803D","#F0FDF4","success-text on green-50"),
 ("#15803D","#D1FAE5","success-text on emerald-100"),
 ("#047857","#F0FDF4","emerald-700 on green-50"),
 ("#B91C1C","#FEE2E2","danger-text on red-100"),
 ("#B91C1C","#FEF2F2","danger-text on red-50"),
 ("#0369A1","#E0F2FE","info-text on sky-100"),
 ("#0369A1","#F0F9FF","info-text on sky-50"),
 ("#0369A1","#EFF6FF","info-text on blue-50"),
 ("#1C72A6","#EAF5F4","teal-text on teal-mist"),
 ("#4C6669","#E2E8F0","ink-soft on slate-200"),
 ("#4C6669","#F1F5F9","ink-soft on slate-100"),
 ("#5C7073","#F1F5F9","ink-faint on slate-100"),
 ("#5C7073","#F8FAFC","ink-faint on slate-50"),
 ("#728B89","#FFFFFF","line-strong on white"),
 ("#9D174D","#FDF2F8","pink-800 on pink-50"),
 ("#BE185D","#FDF2F8","pink-700 on pink-50"),
 ("#A16207","#FEF9C3","yellow-700 on yellow-100"),
 ("#9A3412","#FEF3C7","orange-800 on amber-100"),
 ("#A5D9D9","#155E85","sidebar muted light"),
 ("#9FD1D1","#155E85","sidebar muted 2"),
 ("#A8D8D8","#155E85","sidebar muted 3"),
 ("#8FCBCB","#155E85","sidebar muted 4"),
 ("#BFE3E3","#155E85","sidebar muted 5"),
 ("#FFFFFF","#15803D","white on success fill"),
 ("#FFFFFF","#0369A1","white on info fill"),
 ("#FFFFFF","#B91C1C","white on danger fill"),
 ("#FFFFFF","#1C72A6","white on teal fill"),
 ("#FFFFFF","#2A7FB8","white on lighter teal fill"),
 ("#B45309","#FFFFFF","warning-text on white"),
 ("#4C6669","#0F172A","ink-soft on dark header"),
 ("#93C5FD","#0F172A","light blue on dark header"),
]
for fg,bg,label in tests:
    print("  %5.2f  %-9s on %-9s  %s" % (r(fg,bg),fg,bg,label))
