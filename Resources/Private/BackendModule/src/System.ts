const handle = (generator: Generator, result: any): Promise<any> => {
    if (result.done) {
        return Promise.resolve(result.value);
    }
    return Promise.resolve(result.value).then(
        res => handle(generator, generator.next(res)),
        err => handle(generator, generator.throw && generator.throw(err))
    );
};
function discover(generatorFn: () => Generator<Promise<unknown>, any, unknown>): Promise<any> {
    return new Promise((resolve, reject) => {
        const generator = generatorFn();

        try {
            resolve(handle(generator, generator.next()));
        } catch (ex) {
            reject(ex);
        }
    });
}
export const getAppContainer = discover(function * () {
    return yield new Promise(resolve => {
        document.addEventListener('DOMContentLoaded', () => {
            resolve(document.getElementById('appContainer'));
        });
    });
});
