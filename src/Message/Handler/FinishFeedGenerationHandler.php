<?php

declare(strict_types=1);

namespace Setono\SyliusFeedPlugin\Message\Handler;

use Doctrine\Common\Persistence\ObjectManager;
use InvalidArgumentException;
use League\Flysystem\FilesystemInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Safe\Exceptions\FilesystemException;
use function Safe\fclose;
use function Safe\fopen;
use function Safe\fread;
use function Safe\fwrite;
use function Safe\sprintf;
use Setono\SyliusFeedPlugin\FeedType\FeedTypeInterface;
use Setono\SyliusFeedPlugin\Message\Command\FinishFeedGeneration;
use Setono\SyliusFeedPlugin\Model\FeedInterface;
use Setono\SyliusFeedPlugin\Registry\FeedTypeRegistryInterface;
use Setono\SyliusFeedPlugin\Repository\FeedRepositoryInterface;
use Setono\SyliusFeedPlugin\Workflow\FeedGraph;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Workflow\Registry;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final class FinishFeedGenerationHandler implements MessageHandlerInterface
{
    /** @var FeedRepositoryInterface */
    private $feedRepository;

    /** @var ObjectManager */
    private $feedManager;

    /** @var FilesystemInterface */
    private $filesystem;

    /** @var Registry */
    private $workflowRegistry;

    /** @var Environment */
    private $twig;

    /** @var FeedTypeRegistryInterface */
    private $feedTypeRegistry;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        FeedRepositoryInterface $feedRepository,
        ObjectManager $feedManager,
        FilesystemInterface $filesystem,
        Registry $workflowRegistry,
        Environment $twig,
        FeedTypeRegistryInterface $feedTypeRegistry,
        LoggerInterface $logger
    ) {
        $this->feedRepository = $feedRepository;
        $this->feedManager = $feedManager;
        $this->filesystem = $filesystem;
        $this->workflowRegistry = $workflowRegistry;
        $this->twig = $twig;
        $this->feedTypeRegistry = $feedTypeRegistry;
        $this->logger = $logger;
    }

    /**
     * @throws Throwable
     */
    public function __invoke(FinishFeedGeneration $message): void
    {
        /** @var FeedInterface|null $feed */
        $feed = $this->feedRepository->find($message->getFeedId());

        if (null === $feed) {
            throw new UnrecoverableMessageHandlingException('Feed does not exist');
        }

        try {
            $workflow = $this->workflowRegistry->get($feed, FeedGraph::GRAPH);
        } catch (InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException('An error occurred when trying to get the workflow for the feed', 0, $e);
        }

        try {
            $feedType = $this->feedTypeRegistry->get($feed->getFeedType());

            /** @var ChannelInterface $channel */
            foreach ($feed->getChannels() as $channel) {
                foreach ($channel->getLocales() as $locale) {
                    $dir = sprintf('%s/%s/%s', $feed->getUuid(), $channel->getCode(), $locale->getCode());

                    $chunkStream = $this->getChunkStream();

                    [$feedStart, $feedEnd] = $this->getFeedParts($feed, $feedType, $channel->getCode(),
                        $locale->getCode());

                    fwrite($chunkStream, $feedStart);

                    $files = $this->filesystem->listContents($dir);
                    foreach ($files as $file) {
                        if ('_feed' === $file['basename']) {
                            continue;
                        }

                        $fp = $this->filesystem->readStream($file['path']);
                        if (false === $fp) {
                            throw new FilesystemException(sprintf(
                                'The file "%s" could not be opened as a resource',
                                $file['path']
                            ));
                        }

                        while (!feof($fp)) {
                            fwrite($chunkStream, fread($fp, 8192));
                        }

                        fclose($fp);

                        $this->filesystem->delete($file['path']);
                    }

                    fwrite($chunkStream, $feedEnd);

                    $res = $this->filesystem->writeStream($dir . '/_feed', $chunkStream);

                    try {
                        // tries to close the file pointer although it may already have been closed by flysystem
                        fclose($chunkStream);
                    } catch (FilesystemException $e) {
                    }

                    if (false === $res) {
                        throw new RuntimeException('An error occurred when trying to write the finished feed write');
                    }
                }
            }

            if (!$workflow->can($feed, FeedGraph::TRANSITION_PROCESSED)) {
                throw new RuntimeException(sprintf(
                    'The feed %s can not be marked as ready because the feed is in a wrong state (%s)',
                    $feed->getId(), $feed->getState()
                ));
            }

            $workflow->apply($feed, FeedGraph::TRANSITION_PROCESSED);

            $this->feedManager->flush();
        } catch (Throwable $e) {
            $this->logger->critical($e->getMessage(), ['feedId' => $feed->getId()]);

            $workflow->apply($feed, FeedGraph::TRANSITION_ERRORED);
            $this->feedManager->flush();

            throw $e;
        }
    }

    /**
     * @return resource
     *
     * @throws FilesystemException
     */
    private function getChunkStream()
    {
        do {
            $path = sys_get_temp_dir() . '/' . uniqid('chunk-', true);
        } while (file_exists($path));

        return fopen($path, 'wb+'); // needs to be w+ because we use it for reading later
    }

    /**
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function getFeedParts(
        FeedInterface $feed,
        FeedTypeInterface $feedType,
        string $channel,
        string $locale
    ): array {
        $template = $this->twig->load('@SetonoSyliusFeedPlugin/Template/feed.txt.twig');

        $content = $template->render(
            array_merge(
                $feedType->getFeedContext()->getContext($feed, $channel, $locale),
                ['feed' => $feedType->getTemplate()]
            )
        );

        return explode('<!-- ITEM_BOUNDARY -->', $content);
    }
}
