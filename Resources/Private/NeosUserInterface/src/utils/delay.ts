export default function delay(timeInMilliseconds: number): Promise<void> {
    // @ts-ignore
    return new Promise(resolve => setTimeout(resolve, timeInMilliseconds));
}
