# -*- coding: utf-8 -*-
def srgb(c):
    c=c/255.0
    return c/12.92 if c<=0.03928 else ((c+0.055)/1.055)**2.4
def lum(h):
    h=h.lstrip('#')
    return 0.2126*srgb(int(h[0:2],16))+0.7152*srgb(int(h[2:4],16))+0.0722*srgb(int(h[4:6],16))
def r(a,b):
    L1,L2=lum(a),lum(b)
    if L1<L2: L1,L2=L2,L1
    return (L1+0.05)/(L2+0.05)
for fg,bg,lab in [
 ("#4338CA","#EEF2FF","indigo-700 on indigo-50 (light)"),
 ("#4F46E5","#EEF2FF","indigo-600 on indigo-50 (current)"),
 ("#A5B4FC","#112433","indigo-300 on dark card"),
 ("#A5B4FC","#1E3A5F","indigo-300 on dark gradient card"),
 ("#F472B6","#112433","pink-400 on dark card"),
 ("#F472B6","#1E3A5F","pink-400 on dark gradient card"),
 ("#BE185D","#FDF2F8","pink-700 on pink-50"),
 ("#15803D","#F0FDF4","success-text on green-50"),
 ("#4ADE80","#1E3A5F","dark success on gradient card"),
 ("#A5D9D9","#155E85","sidebar muted"),
 ("#728B89","#FFFFFF","line-strong (old sep)"),
 ("#5C7073","#FFFFFF","ink-faint"),
]:
    print("  %5.2f  %-9s on %-9s  %s" % (r(fg,bg),fg,bg,lab))
