<?php

namespace nineteenninetyfour\reviews\controllers;

use Craft;
use craft\web\Controller;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A review in the control panel: reading it, moderating it, and replying.
 * What the reviewer wrote can't be changed.
 */
class ModerateController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_MODERATE);

        return parent::beforeAction($action);
    }

    public function actionEdit(int $reviewId): Response
    {
        $review = $this->review($reviewId);

        return $this->renderTemplate('reviews/_edit', [
            'review' => $review,
            'element' => $review->getElement(),
            'criteria' => Plugin::getInstance()->reviews->criteria(),
            'canDelete' => static::currentUser()?->can(Plugin::PERMISSION_DELETE) ?? false,
            'summary' => $review->elementId ? Plugin::getInstance()->reviews->summary((int) $review->elementId) : null,
        ]);
    }

    public function actionApprove(): ?Response
    {
        return $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->approve($review), Craft::t('reviews', 'Review approved.'));
    }

    public function actionReject(): ?Response
    {
        $note = $this->request->getBodyParam('note');

        return $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->reject($review, is_string($note) ? $note : null), Craft::t('reviews', 'Review rejected.'));
    }

    public function actionSpam(): ?Response
    {
        return $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->spam($review), Craft::t('reviews', 'Review marked as spam.'));
    }

    public function actionHold(): ?Response
    {
        return $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->hold($review), Craft::t('reviews', 'Review is waiting again.'));
    }

    public function actionReply(): ?Response
    {
        $reply = $this->request->getBodyParam('reply');

        return $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->reply($review, is_string($reply) ? $reply : null, static::currentUser()), Craft::t('reviews', 'Reply saved.'));
    }

    public function actionNote(): ?Response
    {
        $note = trim((string) $this->request->getBodyParam('note', ''));

        return $this->moderate(function (Review $review) use ($note) {
            $review->note = $note === '' ? null : $note;

            return Plugin::getInstance()->reviews->save($review, false);
        }, Craft::t('reviews', 'Note saved.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $this->moderate(fn (Review $review) => Plugin::getInstance()->reviews->delete($review), Craft::t('reviews', 'Review deleted.'));

        return $this->redirect('reviews');
    }

    /**
     * A photo that's waiting, which only editors can see.
     */
    public function actionPhoto(int $reviewId, int $index): Response
    {
        $contents = Plugin::getInstance()->photos->pendingContents($this->review($reviewId), $index);

        if ($contents === null) {
            throw new NotFoundHttpException;
        }

        $this->response->getHeaders()->set('Cache-Control', 'private, no-store');

        return $this->response->sendContentAsFile($contents, 'photo.jpg', ['mimeType' => 'image/jpeg', 'inline' => true]);
    }

    /**
     * @param  callable(Review): bool  $change
     */
    protected function moderate(callable $change, string $message): ?Response
    {
        $this->requirePostRequest();
        $review = $this->review((int) $this->request->getRequiredBodyParam('reviewId'));

        if (! $change($review)) {
            return $this->asFailure(Craft::t('reviews', 'The review couldn’t be saved.'));
        }

        return $this->asSuccess($message, ['status' => $review->getStatus()]);
    }

    protected function review(int $id): Review
    {
        $review = Review::find()->id($id)->status(null)->one();

        if (! $review) {
            throw new NotFoundHttpException(Craft::t('reviews', 'That review couldn’t be found.'));
        }

        return $review;
    }
}
