// Horizontal touch intent only; vertical scrolling and mouse selection are untouched.
export function swipeMark(start, end, revealed) {
 if(!revealed || !start || !end || start.id!==end.id)return null;
 const dx=end.x-start.x,dy=end.y-start.y;
 if(Math.abs(dx)<65 || Math.abs(dx)<Math.abs(dy)*1.5)return null;
 return dx>0;
}
