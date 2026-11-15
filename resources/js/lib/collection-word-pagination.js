export function collectionWordQuery(page,poolIds=[],shared=false){
 const number=Number(page);
 return {words_page:Number.isInteger(number)&&number>0?number:1,...(!shared&&Array.isArray(poolIds)?{pool_ids:[...poolIds]}:{})};
}
